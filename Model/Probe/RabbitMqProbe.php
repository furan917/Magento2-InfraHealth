<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Model\Probe;

use Furan\InfraHealth\Model\HttpJson;
use Furan\InfraHealth\Api\ProbeInterface;
use Furan\InfraHealth\Model\ProbeResult;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\MessageQueue\Topology\ConfigInterface as TopologyConfig;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;

/**
 * Queue stats from the management API, falling back to passive declares over AMQP.
 * Override the API URL with env.php 'infra_health' => ['rabbitmq_management_url' => '...'].
 */
class RabbitMqProbe implements ProbeInterface
{
    private const CONNECT_TIMEOUT = 2.0;
    private const AMQP_BUDGET_SECONDS = 3.0;
    private const MAX_QUEUE_ROWS = 25;
    private const BACKLOG_WARN = 1000;

    public function __construct(
        private DeploymentConfig $deploymentConfig,
        private HttpJson $http,
        private TopologyConfig $topologyConfig
    ) {
    }

    public function getLabel(): string
    {
        return 'RabbitMQ';
    }

    public function run(): array
    {
        $amqp = (array)$this->deploymentConfig->get('queue/amqp', []);
        if (empty($amqp['host'])) {
            return [(new ProbeResult('rabbitmq', 'RabbitMQ'))
                ->setStatus(ProbeResult::STATUS_OFF)
                ->setSummary('Not used — message queues run on MySQL')];
        }

        $host = (string)$amqp['host'];
        $port = (int)($amqp['port'] ?? 5672);
        $tls = !empty($amqp['ssl']) && $amqp['ssl'] !== 'false';
        $user = (string)($amqp['user'] ?? '');
        $password = (string)($amqp['password'] ?? '');
        $vhost = (string)($amqp['virtualhost'] ?? '/');

        $result = new ProbeResult('rabbitmq', 'RabbitMQ', $host . ':' . $port);
        $result->addConfig('AMQP endpoint', $host . ':' . $port);
        $result->addConfig('Virtual host', $vhost);
        $result->addConfig('User', $user ?: '—');
        $result->addConfig('TLS', $tls ? 'enabled' : 'off');

        $start = hrtime(true);
        $socket = @stream_socket_client('tcp://' . $this->hostForUrl($host) . ':' . $port, $errno, $errstr, self::CONNECT_TIMEOUT);
        if (!$socket) {
            return [$result->fail(sprintf('AMQP port unreachable: %s', $errstr ?: 'timeout'))];
        }
        $result->setLatency((hrtime(true) - $start) / 1e6);
        fclose($socket);

        [$mgmtUrl, $overview, $queues, $mgmtErrors] = $this->management($host, $tls, $vhost, $user, $password);
        if ($mgmtUrl !== null) {
            $result->addConfig('Management API', $mgmtUrl);
            $this->fromManagement($result, $overview, $queues, $vhost);
            return [$result];
        }

        if (!class_exists(AMQPConnectionConfig::class)) {
            // php-amqplib before 3.2 (older Magento 2.4.x) has no connection config API.
            $result->setSummary('AMQP port reachable; queue stats need the management API on this php-amqplib version');
            $result->addConfig('Tried', implode('; ', $mgmtErrors));
            return [$result];
        }
        $result->addConfig('Management API', 'not reachable, queue depths read over AMQP');
        try {
            $this->fromAmqp($result, $amqp, $host, $port, $tls, $vhost);
        } catch (\Throwable $e) {
            $result->degrade(ProbeResult::STATUS_WARN)
                ->setSummary('AMQP port reachable, but login failed: ' . $e->getMessage());
        }
        $result->addConfig('Tried', implode('; ', $mgmtErrors));
        return [$result];
    }

    /**
     * @return array{0: ?string, 1: array, 2: array, 3: string[]}
     */
    private function management(string $host, bool $tls, string $vhost, string $user, string $password): array
    {
        $override = (string)$this->deploymentConfig->get('infra_health/rabbitmq_management_url', '');
        $h = $this->hostForUrl($host);
        $candidates = $override !== ''
            ? [rtrim($override, '/')]
            : ($tls ? ["https://$h:15671", "https://$h"] : ["http://$h:15672"]);

        $errors = [];
        foreach ($candidates as $url) {
            try {
                [$overview] = $this->http->get($url . '/api/overview', $user, $password);
                [$queues] = $this->http->get(
                    $url . '/api/queues/' . rawurlencode($vhost) . '?columns=name,messages,messages_ready,'
                    . 'messages_unacknowledged,consumers,message_stats.publish_details.rate,'
                    . 'message_stats.deliver_get_details.rate',
                    $user,
                    $password
                );
                return [$url, (array)$overview, (array)$queues, []];
            } catch (\Throwable $e) {
                $errors[] = $url . ': ' . $e->getMessage();
            }
        }
        return [null, [], [], $errors];
    }

    private function fromManagement(ProbeResult $result, array $overview, array $queues, string $vhost): void
    {
        $result->setSummary(sprintf(
            'RabbitMQ %s on Erlang %s',
            $overview['rabbitmq_version'] ?? '?',
            $overview['erlang_version'] ?? '?'
        ));
        $result->addConfig('Version', $overview['rabbitmq_version'] ?? '?');
        $result->addConfig('Cluster', $overview['cluster_name'] ?? '?');

        $rows = [];
        foreach ($queues as $queue) {
            $rows[] = [
                'name' => (string)($queue['name'] ?? '?'),
                'ready' => (int)($queue['messages_ready'] ?? 0),
                'unacked' => (int)($queue['messages_unacknowledged'] ?? 0),
                'consumers' => (int)($queue['consumers'] ?? 0),
                'in' => (float)($queue['message_stats']['publish_details']['rate'] ?? 0),
                'out' => (float)($queue['message_stats']['deliver_get_details']['rate'] ?? 0),
            ];
        }

        $stats = (array)($overview['message_stats'] ?? []);
        $this->addQueueStats($result, $rows, $vhost, true);
        $result->addStat('publish', 'Publish / sec', round((float)($stats['publish_details']['rate'] ?? 0), 2));
        $result->addStat('deliver', 'Deliver / sec', round((float)($stats['deliver_get_details']['rate'] ?? 0), 2));
        $result->addStat('connections', 'Connections', (int)($overview['object_totals']['connections'] ?? 0));
    }

    /**
     * Passive declares for every AMQP queue Magento defines. Missing queues are skipped.
     */
    private function fromAmqp(ProbeResult $result, array $amqp, string $host, int $port, bool $tls, string $vhost): void
    {
        $config = new AMQPConnectionConfig();
        $config->setHost($host);
        $config->setPort($port);
        $config->setUser((string)($amqp['user'] ?? ''));
        $config->setPassword((string)($amqp['password'] ?? ''));
        $config->setVhost($vhost);
        $config->setConnectionTimeout(self::CONNECT_TIMEOUT);
        $config->setReadTimeout(self::CONNECT_TIMEOUT);
        $config->setWriteTimeout(self::CONNECT_TIMEOUT);
        $config->setIsSecure($tls);
        if ($tls) {
            // Same option mapping as Magento's own AMQP connection factory.
            $ssl = (array)($amqp['ssl_options'] ?? []);
            $config->setSslVerify(!isset($ssl['verify_peer']) || (bool)$ssl['verify_peer']);
            isset($ssl['verify_peer_name']) && $config->setSslVerifyName((bool)$ssl['verify_peer_name']);
            isset($ssl['cafile']) && $config->setSslCaCert((string)$ssl['cafile']);
            isset($ssl['local_cert']) && $config->setSslCert((string)$ssl['local_cert']);
            isset($ssl['local_pk']) && $config->setSslKey((string)$ssl['local_pk']);
            isset($ssl['passphrase']) && $config->setSslPassPhrase((string)$ssl['passphrase']);
        }

        $connection = AMQPConnectionFactory::create($config);
        $serverProps = $connection->getServerProperties();
        $version = (string)($serverProps['version'][1] ?? '?');
        $result->setSummary('RabbitMQ ' . $version . ', stats via AMQP (no rates without the management API)');
        $result->addConfig('Version', $version);

        $names = [];
        foreach ($this->topologyConfig->getQueues() as $queue) {
            if ($queue->getConnection() === 'amqp') {
                $names[$queue->getName()] = true;
            }
        }

        $rows = [];
        $missing = 0;
        $deadline = microtime(true) + self::AMQP_BUDGET_SECONDS;
        $channel = $connection->channel();
        foreach (array_keys($names) as $name) {
            if (microtime(true) > $deadline) {
                $result->addConfig('Note', 'Stopped after ' . count($rows) . ' queues to stay within the time budget');
                break;
            }
            try {
                // passive=true: only reports on an existing queue, never declares one.
                [, $ready, $consumers] = $channel->queue_declare((string)$name, true);
                $rows[] = ['name' => (string)$name, 'ready' => (int)$ready, 'unacked' => null,
                    'consumers' => (int)$consumers, 'in' => null, 'out' => null];
            } catch (AMQPProtocolChannelException $e) {
                // 404: queue not created yet. The broker closes the channel, so open a fresh one.
                $missing++;
                $channel = $connection->channel();
            }
        }
        try {
            $channel->close();
            $connection->close();
        } catch (\Throwable $e) {
            // Closing is best effort.
        }

        if ($missing) {
            $result->addConfig('Queues not created yet', (string)$missing);
        }
        $this->addQueueStats($result, $rows, $vhost, false);
    }

    /**
     * @param array<int, array{name: string, ready: int, unacked: ?int, consumers: int, in: ?float, out: ?float}> $rows
     */
    private function addQueueStats(ProbeResult $result, array $rows, string $vhost, bool $withRates): void
    {
        usort($rows, static fn ($a, $b) => $b['ready'] <=> $a['ready'] ?: strcmp($a['name'], $b['name']));

        $ready = array_sum(array_column($rows, 'ready'));
        $idle = count(array_filter($rows, static fn ($r) => $r['ready'] > 0 && $r['consumers'] === 0));
        $table = [];
        $flags = [];
        foreach (array_slice($rows, 0, self::MAX_QUEUE_ROWS) as $r) {
            $line = [$r['name'], $r['ready']];
            if ($withRates) {
                array_push($line, $r['unacked'], $r['consumers'], ['rate' => $r['in']], ['rate' => $r['out']]);
            } else {
                $line[] = $r['consumers'];
            }
            $table[] = $line;
            $flags[] = match (true) {
                $r['ready'] >= self::BACKLOG_WARN => 'down',
                $r['ready'] > 0 && $r['consumers'] === 0 => 'warn',
                default => '',
            };
        }

        $result->addStat('queues', 'Queues', count($rows));
        $result->addStat('ready', 'Ready', $ready);
        if ($withRates) {
            $result->addStat('unacked', 'Unacked', array_sum(array_column($rows, 'unacked')));
        }
        $result->addStat('consumers', 'Consumers', array_sum(array_column($rows, 'consumers')));
        $result->addTable(
            sprintf('Queues in vhost %s%s', $vhost, count($rows) > self::MAX_QUEUE_ROWS ? ' (top ' . self::MAX_QUEUE_ROWS . ')' : ''),
            $withRates ? ['Queue', 'Ready', 'Unacked', 'Consumers', 'In / s', 'Out / s'] : ['Queue', 'Ready', 'Consumers'],
            $table,
            $flags
        );

        if ($ready >= self::BACKLOG_WARN) {
            $result->degrade(ProbeResult::STATUS_WARN);
        }
        if ($idle > 0) {
            $result->addConfig('Backlog without consumers', $idle . ' queue' . ($idle === 1 ? '' : 's'));
        }
    }

    private function hostForUrl(string $host): string
    {
        return str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host;
    }
}
