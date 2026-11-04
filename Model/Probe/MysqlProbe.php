<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Model\Probe;

use Furan\InfraHealth\Api\ProbeInterface;
use Furan\InfraHealth\Model\ProbeResult;
use Furan\InfraHealth\Model\ReadConnections;
use Furan\InfraHealth\Model\Timer;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\ResourceModel\Type\Db\ConnectionFactory;

/**
 * Writer and read connections, with role and replica lag. SELECT / SHOW only.
 */
class MysqlProbe implements ProbeInterface
{
    private const STATUS_VARS = [
        'Uptime', 'Threads_connected', 'Threads_running', 'Questions', 'Slow_queries',
        'Innodb_buffer_pool_read_requests', 'Innodb_buffer_pool_reads', 'Max_used_connections',
        'Aborted_connects', 'Innodb_row_lock_waits',
    ];
    private const ROLE_VARS = [
        'max_connections', 'innodb_buffer_pool_size', 'read_only', 'super_read_only', 'innodb_read_only',
        'aurora_version',
    ];
    private const LAG_WARN_SECONDS = 30;
    private const READ_CONNECT_TIMEOUT = 2;

    public function __construct(
        private ResourceConnection $resource,
        private DeploymentConfig $deploymentConfig,
        private ConnectionFactory $connectionFactory,
        private ReadConnections $readConnections
    ) {
    }

    public function getLabel(): string
    {
        return 'MySQL';
    }

    public function run(): array
    {
        $db = (array)$this->deploymentConfig->get('db/connection/default', []);
        $split = $this->readConnections->get();

        $writer = $this->probe(
            new ProbeResult('mysql', 'MySQL', (string)($db['host'] ?? '')),
            array_diff_key($db, array_flip(['slaves'])),
            fn () => $this->resource->getConnection(),
            true
        );
        $writer->addConfig('Read/write split', $split['splitters'] ? implode(', ', $split['splitters'])
            . ' (' . count($split['readers']) . ' reader' . (count($split['readers']) === 1 ? '' : 's') . ')' : 'none');
        if ($split['switched_off']) {
            $writer->addConfig('Readers switched off', (string)$split['switched_off']);
        }
        foreach ($split['notes'] as $note) {
            $writer->addTip($note);
        }
        $results = [$writer];

        foreach ($split['readers'] as $reader) {
            $config = $reader['config'];
            $results[] = $this->probe(
                new ProbeResult(
                    'mysql',
                    'MySQL ' . strtolower($reader['name']) . ' (' . $this->readConnections->label($reader['splitter']) . ')',
                    (string)($config['host'] ?? '')
                ),
                $config,
                fn () => $this->connectionFactory->create(array_replace_recursive($config, [
                    'active' => '1',
                    'driver_options' => [\PDO::ATTR_TIMEOUT => self::READ_CONNECT_TIMEOUT],
                ])),
                false
            );
        }
        return $results;
    }

    /**
     * @param callable(): AdapterInterface $connect
     */
    private function probe(ProbeResult $result, array $db, callable $connect, bool $mustWrite): ProbeResult
    {
        $host = (string)($db['host'] ?? '?');
        $result->addConfig('Host', $host);
        $result->addConfig('Database', $db['dbname'] ?? '?');

        try {
            $connection = $connect();
            $result->setLatency(Timer::average(static fn () => $connection->fetchOne('SELECT 1')));
            $version = (string)$connection->fetchOne('SELECT VERSION()');
            $status = $connection->fetchPairs(
                "SHOW GLOBAL STATUS WHERE Variable_name IN ('" . implode("','", self::STATUS_VARS) . "')"
            );
            $vars = $connection->fetchPairs(
                "SHOW GLOBAL VARIABLES WHERE Variable_name IN ('" . implode("','", self::ROLE_VARS) . "')"
            );
            $size = $mustWrite ? (int)$connection->fetchOne(
                'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()'
            ) : null;
        } catch (\Throwable $e) {
            $result->addConfig('Endpoint type', $this->endpointType($host, false));
            return $result->fail($e);
        }

        $aurora = isset($vars['aurora_version']);
        $endpointType = $this->endpointType($host, $aurora);
        $result->addConfig('Endpoint type', $endpointType);
        $on = static fn (string $name) => strtoupper((string)($vars[$name] ?? 'OFF')) === 'ON' || ($vars[$name] ?? '') === '1';
        $readOnly = $aurora ? $on('innodb_read_only') : ($on('read_only') || $on('super_read_only'));
        $flavour = $aurora ? 'Aurora MySQL' : (stripos($version, 'mariadb') !== false ? 'MariaDB' : 'MySQL');

        $uptime = max(1, (int)($status['Uptime'] ?? 1));
        $maxConnections = (int)($vars['max_connections'] ?? 0);
        $connected = (int)($status['Threads_connected'] ?? 0);
        $requests = (int)($status['Innodb_buffer_pool_read_requests'] ?? 0);
        $diskReads = (int)($status['Innodb_buffer_pool_reads'] ?? 0);

        $result->setSummary(sprintf(
            '%s %s, %s, up %dd %dh',
            $flavour,
            $aurora ? $vars['aurora_version'] : preg_replace('/-.*$/', '', $version),
            $readOnly ? 'read-only' : 'writer',
            intdiv($uptime, 86400),
            intdiv($uptime % 86400, 3600)
        ));
        $result->addConfig('Role', $readOnly ? ($aurora ? 'Aurora reader' : 'read-only replica') : 'writer');
        $result->addConfig('Version', $version);
        $result->addConfig('Buffer pool', $this->bytes((int)($vars['innodb_buffer_pool_size'] ?? 0)));
        $result->addConfig('Max connections', $maxConnections);

        if ($mustWrite && $readOnly) {
            $result->setStatus(ProbeResult::STATUS_DOWN);
            $result->setSummary('Magento\'s main connection reached a read-only instance. Orders, carts, logins and'
                . ' cache invalidation will fail to write. Point db/connection/default at the writer'
                . ($aurora ? ' (cluster) endpoint.' : '.'));
        } elseif (!$mustWrite && !$readOnly) {
            $result->addTip('The read connection reaches a writer, so reads are not being offloaded.'
                . ($aurora ? ' Use the cluster reader endpoint (cluster-ro-) for db/slave_connection.' : ''));
        }
        if ($mustWrite && $endpointType === 'Aurora instance endpoint') {
            $result->addTip('This connects to a single Aurora instance. After a failover that instance becomes a reader'
                . ' and writes stop. The cluster endpoint follows the writer automatically.');
        }

        $result->addStat('connections', 'Connections', $connected);
        $result->addStat('running', 'Running threads', (int)($status['Threads_running'] ?? 0));
        $result->addStat('qps', 'Queries / sec (avg)', round((int)($status['Questions'] ?? 0) / $uptime, 1));
        $result->addStat('slow', 'Slow queries', (int)($status['Slow_queries'] ?? 0));
        if ($size !== null) {
            $result->addStat('size', 'Schema size', $size, 'bytes');
        }
        $result->addStat('lock_waits', 'Row lock waits', (int)($status['Innodb_row_lock_waits'] ?? 0));

        $lag = $aurora ? $this->auroraCluster($connection, $result) : ($readOnly ? $this->replicaLag($connection) : null);
        if ($lag !== null) {
            $result->addStat('lag', 'Replica lag', $lag, 'ms');
            if ($lag / 1000 > self::LAG_WARN_SECONDS) {
                $result->degrade(ProbeResult::STATUS_WARN);
            }
        }

        $result->addGauge(
            'Buffer pool hits',
            $requests ? (1 - $diskReads / $requests) * 100 : null,
            number_format($diskReads) . ' disk reads',
            true
        );
        $result->addGauge(
            'Connections',
            $maxConnections ? $connected / $maxConnections * 100 : null,
            $connected . ' of ' . $maxConnections
        );
        $result->addRate('qps_now', 'Queries / sec (now)', 'questions');
        $result->setCounters(['questions' => (int)($status['Questions'] ?? 0)]);

        if ($maxConnections && $connected / $maxConnections > 0.8) {
            $result->degrade(ProbeResult::STATUS_WARN);
        }
        return $result;
    }

    /**
     * Aurora lists every cluster member and its lag in information_schema; no extra privileges needed.
     *
     * @return float|null Highest reader lag in ms
     */
    private function auroraCluster(AdapterInterface $connection, ProbeResult $result): ?float
    {
        try {
            $rows = $connection->fetchAll(
                'SELECT server_id, session_id, replica_lag_in_milliseconds AS lag FROM information_schema.replica_host_status'
                . ' WHERE last_update_timestamp > NOW() - INTERVAL 5 MINUTE ORDER BY server_id'
            );
        } catch (\Throwable $e) {
            return null;
        }
        $table = [];
        $maxLag = null;
        foreach ($rows as $row) {
            $writer = ($row['session_id'] ?? '') === 'MASTER_SESSION_ID';
            $lag = $writer ? null : (float)$row['lag'];
            $maxLag = $lag === null ? $maxLag : max($maxLag ?? 0.0, $lag);
            $table[] = [(string)$row['server_id'], $writer ? 'writer' : 'reader', $writer ? '—' : round($lag, 1)];
        }
        if ($table) {
            $result->addTable('Aurora cluster members', ['Instance', 'Role', 'Lag (ms)'], $table);
        }
        return $maxLag;
    }

    /**
     * Classic MySQL/RDS replica lag. Needs REPLICATION CLIENT, so a refusal just means "not shown".
     */
    private function replicaLag(AdapterInterface $connection): ?float
    {
        foreach (['SHOW REPLICA STATUS', 'SHOW SLAVE STATUS'] as $sql) {
            try {
                $row = $connection->fetchRow($sql);
            } catch (\Throwable $e) {
                continue;
            }
            $seconds = $row['Seconds_Behind_Source'] ?? $row['Seconds_Behind_Master'] ?? null;
            return $seconds === null || $seconds === '' ? null : (float)$seconds * 1000;
        }
        return null;
    }

    private function endpointType(string $host, bool $aurora): string
    {
        return match (true) {
            str_contains($host, '.cluster-ro-') => 'Aurora reader endpoint',
            str_contains($host, '.cluster-custom-') => 'Aurora custom endpoint',
            str_contains($host, '.cluster-') => 'Aurora cluster (writer) endpoint',
            str_contains($host, '.proxy-') && str_contains($host, '.rds.amazonaws.com') => 'RDS Proxy',
            str_contains($host, '.rds.amazonaws.com') && $aurora => 'Aurora instance endpoint',
            str_contains($host, '.rds.amazonaws.com') => 'RDS instance',
            str_contains($host, '.mysql.database.azure.com') => 'Azure Database for MySQL',
            str_contains($host, '.mysql.') && str_contains($host, 'digitalocean') => 'DigitalOcean Managed MySQL',
            default => 'host',
        };
    }

    private function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = $bytes > 0 ? (int)floor(log($bytes, 1024)) : 0;
        return round($bytes / (1024 ** $i), 1) . ' ' . $units[$i];
    }
}
