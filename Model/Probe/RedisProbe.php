<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Model\Probe;

use Furan\InfraHealth\Api\ProbeInterface;
use Furan\InfraHealth\Model\ProbeResult;
use Furan\InfraHealth\Model\RedisTargets;
use Furan\InfraHealth\Model\Timer;

/**
 * INFO / PING only. Never KEYS, SCAN or anything that touches data.
 */
class RedisProbe implements ProbeInterface
{
    private const TIMEOUT = 1.5;

    public function __construct(private RedisTargets $targets)
    {
    }

    public function getLabel(): string
    {
        return 'Redis';
    }

    public function run(): array
    {
        $servers = $this->targets->servers();
        if (!$servers) {
            return [(new ProbeResult('redis', 'Redis'))
                ->setStatus(ProbeResult::STATUS_OFF)
                ->setSummary('Not used — cache, page cache and sessions use other backends')];
        }

        $results = [];
        foreach ($servers as $id => $server) {
            $results[] = $this->probe((string)$id, $server);
        }
        return $results;
    }

    private function probe(string $id, array $server): ProbeResult
    {
        $result = new ProbeResult('redis', 'Redis', $id);
        $result->addConfig('Endpoint', $id);
        $result->addConfig('Auth', match (true) {
            ($server['username'] ?? '') !== '' => 'ACL user set',
            $server['password'] !== '' => 'password set',
            default => 'none',
        });
        $result->addConfig('TLS', str_starts_with($server['host'], 'tls://') ? 'enabled' : 'off');
        if (($server['sentinel_master'] ?? '') !== '') {
            $result->addConfig('Sentinel master', $server['sentinel_master']);
        }
        foreach ($server['roles'] as $role) {
            $result->addConfig($role['role'], 'db ' . $role['database']);
        }

        try {
            $client = new \Credis_Client(
                $this->connectHost($server['host']),
                $server['port'],
                self::TIMEOUT,
                '',
                0,
                $server['password'] !== '' ? $server['password'] : null,
                ($server['username'] ?? '') !== '' ? $server['username'] : null
            );
            $client->setReadTimeout(self::TIMEOUT);
            $client->connect();
            $result->setLatency(Timer::average(static fn () => $client->ping()));
            $info = (array)$client->info();
            $client->close();
        } catch (\Throwable $e) {
            return $result->fail($e);
        }

        $hits = (int)($info['keyspace_hits'] ?? 0);
        $misses = (int)($info['keyspace_misses'] ?? 0);
        $used = (int)($info['used_memory'] ?? 0);
        $max = (int)($info['maxmemory'] ?? 0);
        $evicted = (int)($info['evicted_keys'] ?? 0);

        $result->setSummary(sprintf(
            'v%s %s, up %s',
            $info['redis_version'] ?? $info['valkey_version'] ?? '?',
            $info['role'] ?? 'master',
            $this->duration((int)($info['uptime_in_seconds'] ?? 0))
        ));
        $result->addConfig('Version', $info['redis_version'] ?? '?');
        $result->addConfig('Eviction policy', $info['maxmemory_policy'] ?? '?');
        $result->addConfig('Max memory', $max ? $info['maxmemory_human'] ?? $max : 'unlimited');

        $totalKeys = 0;
        $dbRows = [];
        foreach ($info as $key => $value) {
            if (preg_match('/^db(\d+)$/', (string)$key, $m)) {
                parse_str(str_replace(',', '&', (string)$value), $db);
                $totalKeys += (int)($db['keys'] ?? 0);
                $dbRows[] = [
                    'db ' . $m[1],
                    $this->rolesFor((int)$m[1], $server['roles']),
                    (int)($db['keys'] ?? 0),
                    (int)($db['expires'] ?? 0),
                ];
            }
        }

        $result->addStat('keys', 'Keys', $totalKeys);
        $result->addStat('ops', 'Ops / sec', (int)($info['instantaneous_ops_per_sec'] ?? 0));
        $result->addStat('memory', 'Memory', $used, 'bytes');
        $result->addStat('clients', 'Clients', (int)($info['connected_clients'] ?? 0));
        $result->addStat('evicted', 'Evicted keys', $evicted);
        $result->addStat('frag', 'Fragmentation', (float)($info['mem_fragmentation_ratio'] ?? 0), 'x');

        $result->addGauge(
            'Hit ratio',
            ($hits + $misses) ? $hits / ($hits + $misses) * 100 : null,
            number_format($hits) . ' hits / ' . number_format($misses) . ' misses',
            true
        );
        $result->addGauge(
            'Memory',
            $max ? $used / $max * 100 : null,
            $max ? ($info['used_memory_human'] ?? '') . ' of ' . ($info['maxmemory_human'] ?? '') : 'no maxmemory set'
        );
        if ($dbRows) {
            $result->addTable('Databases', ['DB', 'Used for', 'Keys', 'With TTL'], $dbRows);
        }
        $result->addRate('hit_now', 'Hit ratio (now)', 'hits', 'lookups', '%', 100);
        $result->setCounters([
            'commands' => (int)($info['total_commands_processed'] ?? 0),
            'hits' => $hits,
            'lookups' => $hits + $misses,
        ]);

        $this->checkRole($result, $info, $server, $id);

        if ($max && $used / $max > 0.9) {
            $result->degrade(ProbeResult::STATUS_WARN);
        }
        if ($max && $evicted > 0 && ($info['maxmemory_policy'] ?? '') === 'noeviction') {
            $result->degrade(ProbeResult::STATUS_WARN);
        }
        return $result;
    }

    /**
     * Does the endpoint's real role (primary/replica, cluster mode) fit what Magento does with it?
     */
    private function checkRole(ProbeResult $result, array $info, array $server, string $id): void
    {
        $replica = in_array($info['role'] ?? 'master', ['slave', 'replica'], true);
        $writes = array_column(array_filter($server['roles'], static fn ($r) => empty($r['read'])), 'role');
        $reads = array_column(array_filter($server['roles'], static fn ($r) => !empty($r['read'])), 'role');

        $result->addConfig('Endpoint type', $this->endpointType($server['host']));
        $result->addConfig('Role', $replica ? 'replica (read-only)' : 'primary');

        if ($replica) {
            $result->addConfig('Primary', ($info['master_host'] ?? '?') . ':' . ($info['master_port'] ?? '?'));
            $result->addStat('lag', 'Seconds since primary sync', (int)($info['master_last_io_seconds_ago'] ?? -1));
            if (($info['master_link_status'] ?? 'up') !== 'up') {
                $result->degrade(ProbeResult::STATUS_WARN);
                $result->addConfig('Replication', 'link to primary is ' . ($info['master_link_status'] ?? 'down'));
            }
            if ($writes) {
                $result->setStatus(ProbeResult::STATUS_DOWN);
                $result->setSummary(sprintf(
                    '%s is a read-only replica, but Magento writes %s here. Point it at the primary endpoint.',
                    $id,
                    implode(', ', $writes)
                ));
            }
        } else {
            $result->addConfig('Replicas attached', (string)(int)($info['connected_slaves'] ?? 0));
            if ($reads) {
                $result->addTip(implode(', ', $reads) . ' are set to use a replica, but this endpoint is the primary,'
                    . ' so reads are not being offloaded. Use the reader endpoint for load_from_slave.');
            }
        }

        if ((string)($info['cluster_enabled'] ?? '0') === '1') {
            $result->degrade(ProbeResult::STATUS_WARN);
            $result->addConfig('Cluster mode', 'enabled');
            if ($result->getStatus() !== ProbeResult::STATUS_DOWN) {
                $result->setSummary('Cluster mode is on. Magento\'s Redis client does not follow cluster redirects, so'
                    . ' keys stored on other shards will fail. Use a replication group with cluster mode disabled.');
            }
        }
    }

    private function endpointType(string $host): string
    {
        $host = (string)preg_replace('#^\w+://#', '', $host);
        return match (true) {
            str_contains($host, '.serverless.') && str_contains($host, '.cache.amazonaws.com') => 'ElastiCache Serverless',
            str_contains($host, '.clustercfg.') => 'ElastiCache cluster configuration endpoint',
            str_starts_with($host, 'master.') && str_contains($host, '.cache.amazonaws.com') => 'ElastiCache primary endpoint',
            (str_starts_with($host, 'replica.') || str_contains($host, '-ro.')) && str_contains($host, '.cache.amazonaws.com')
                => 'ElastiCache reader endpoint',
            str_contains($host, '.cache.amazonaws.com') => 'ElastiCache node endpoint',
            str_contains($host, '.memorydb.') => 'Amazon MemoryDB',
            str_contains($host, '.redis.cache.windows.net') || str_contains($host, '.redis.azure.net') => 'Azure Cache for Redis',
            str_contains($host, 'db.ondigitalocean.com') => 'DigitalOcean Managed Redis/Valkey',
            str_starts_with($host, '/') => 'Unix socket',
            default => 'host',
        };
    }

    /**
     * Bracket bare IPv6 addresses; leave tls://, tcp:// and unix socket paths alone.
     */
    private function connectHost(string $host): string
    {
        return str_contains($host, ':') && !str_contains($host, '://') && !str_starts_with($host, '[')
            ? '[' . $host . ']'
            : $host;
    }

    private function rolesFor(int $database, array $roles): string
    {
        $names = array_column(array_filter($roles, static fn ($r) => $r['database'] === $database), 'role');
        return $names ? implode(', ', $names) : '—';
    }

    private function duration(int $seconds): string
    {
        return match (true) {
            $seconds >= 86400 => floor($seconds / 86400) . 'd',
            $seconds >= 3600 => floor($seconds / 3600) . 'h',
            default => floor($seconds / 60) . 'm',
        };
    }
}
