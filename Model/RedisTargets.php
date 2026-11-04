<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Model;

use Magento\Framework\App\DeploymentConfig;

/**
 * Discovers Redis endpoints from env.php (cache frontends, L2 cache, sessions).
 */
class RedisTargets
{
    private const CACHE_FRONTENDS = ['default' => 'Cache', 'page_cache' => 'Page cache'];

    public function __construct(private DeploymentConfig $deploymentConfig)
    {
    }

    /**
     * @return array{id: string, host: string, port: int, password: string, database: int, l2: bool}|null
     */
    public function cache(string $frontend): ?array
    {
        $config = (array)$this->deploymentConfig->get("cache/frontend/$frontend", []);
        $backend = (string)($config['backend'] ?? '');
        $options = (array)($config['backend_options'] ?? []);
        $l2 = false;

        if (stripos($backend, 'RemoteSynchronizedCache') !== false) {
            $backend = (string)($options['remote_backend'] ?? '');
            $options = (array)($options['remote_backend_options'] ?? []);
            $l2 = true;
        }
        if (stripos($backend, 'redis') === false) {
            return null;
        }

        $target = $this->target(
            (string)($options['server'] ?? '127.0.0.1'),
            (int)($options['port'] ?? 6379),
            (string)($options['password'] ?? ''),
            (int)($options['database'] ?? 0),
            $l2,
            (string)($options['username'] ?? $options['user'] ?? ''),
            (string)($options['sentinel_master'] ?? '')
        );
        $target['read_replica'] = $this->readReplica($options['load_from_slave'] ?? null, $target);
        return $target;
    }

    /**
     * Cache reads can be sent to a replica (backend option load_from_slave, e.g. 'tcp://replica:6379').
     */
    private function readReplica(mixed $value, array $primary): ?array
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        $value = trim((string)$value);
        if ($value === '' || $value === '0') {
            return null;
        }
        $parts = parse_url(str_contains($value, '://') ? $value : 'tcp://' . $value) ?: [];
        $scheme = ($parts['scheme'] ?? 'tcp') === 'tls' ? 'tls://' : '';
        return $this->target(
            $scheme . ($parts['host'] ?? $value),
            (int)($parts['port'] ?? $primary['port']),
            isset($parts['pass']) ? rawurldecode($parts['pass']) : $primary['password'],
            $primary['database'],
            false,
            $primary['username']
        );
    }

    public function session(): ?array
    {
        if ($this->deploymentConfig->get('session/save') !== 'redis') {
            return null;
        }
        $options = (array)$this->deploymentConfig->get('session/redis', []);
        return $this->target(
            (string)($options['host'] ?? '127.0.0.1'),
            (int)($options['port'] ?? 6379),
            (string)($options['password'] ?? ''),
            (int)($options['database'] ?? 0),
            false,
            (string)($options['username'] ?? ''),
            (string)($options['sentinel_master'] ?? '')
        );
    }

    /**
     * Unique servers with the roles (and databases) each one serves.
     *
     * @return array<string, array{host: string, port: int, password: string, roles: array<int, array{role: string, database: int}>}>
     */
    public function servers(): array
    {
        $roles = [];
        foreach (self::CACHE_FRONTENDS as $frontend => $role) {
            $target = $this->cache($frontend);
            $roles[] = [$role, $target, false];
            if ($target && $target['read_replica']) {
                $roles[] = [$role . ' reads', $target['read_replica'], true];
            }
        }
        $roles[] = ['Sessions', $this->session(), false];

        $servers = [];
        foreach ($roles as [$role, $target, $readOnly]) {
            if (!$target) {
                continue;
            }
            $servers[$target['id']] ??= [
                'host' => $target['host'],
                'port' => $target['port'],
                'password' => $target['password'],
                'username' => $target['username'],
                'sentinel_master' => $target['sentinel_master'],
                'roles' => [],
            ];
            $servers[$target['id']]['roles'][] = ['role' => $role, 'database' => $target['database'], 'read' => $readOnly];
        }
        return $servers;
    }

    private function target(
        string $host,
        int $port,
        string $password,
        int $database,
        bool $l2 = false,
        string $username = '',
        string $sentinelMaster = ''
    ): array {
        $isSocket = str_starts_with($host, '/') || str_starts_with($host, 'unix://');
        return [
            'id' => $isSocket ? $host : $host . ':' . $port,
            'host' => $host,
            'port' => $port,
            'password' => $password,
            'database' => $database,
            'l2' => $l2,
            'username' => $username,
            'sentinel_master' => $sentinelMaster,
        ];
    }
}
