<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Model;

use Furan\InfraHealth\Api\WiringProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * Which backend each Magento subsystem uses. Config only, no network calls.
 */
class Wiring
{
    public function __construct(
        private DeploymentConfig $deploymentConfig,
        private ScopeConfigInterface $scopeConfig,
        private RedisTargets $redisTargets,
        private SearchConfig $searchConfig,
        private StorageLocator $storageLocator,
        private DirectoryList $directoryList,
        private ReadConnections $readConnections,
        private array $providers = []
    ) {
    }

    /**
     * @return array<int, array{subsystem: string, backend: string, target: string, service: ?string, storage?: array}>
     */
    public function get(): array
    {
        $db = (array)$this->deploymentConfig->get('db/connection/default', []);
        $rows = [[
            'subsystem' => 'Database',
            'backend' => 'MySQL',
            'target' => ($db['host'] ?? '?') . ' / ' . ($db['dbname'] ?? '?'),
            'service' => 'mysql:' . ($db['host'] ?? ''),
        ]];
        $readers = $this->readConnections->get()['readers'];
        foreach ($readers as $reader) {
            $rows[] = [
                'subsystem' => count($readers) > 1 ? 'Database reads (' . strtolower($reader['name']) . ')' : 'Database reads',
                'backend' => 'MySQL',
                'target' => ($reader['config']['host'] ?? '?') . ' via ' . $this->readConnections->label($reader['splitter']),
                'service' => 'mysql:' . ($reader['config']['host'] ?? ''),
            ];
        }

        $var = $this->directoryList->getPath(DirectoryList::VAR_DIR);
        $rows[] = $this->cacheRow('Cache', 'default', $this->directoryList->getPath(DirectoryList::CACHE));

        $fpcApp = (int)$this->scopeConfig->getValue('system/full_page_cache/caching_application');
        if ($fpcApp === 2) {
            $hosts = array_map(
                static fn ($h) => ($h['host'] ?? '?') . ':' . ($h['port'] ?? 80),
                (array)$this->deploymentConfig->get('http_cache_hosts', [])
            );
            $rows[] = [
                'subsystem' => 'Full page cache',
                'backend' => 'Varnish',
                'target' => $hosts ? implode(', ', $hosts) : 'purge hosts not set',
                'service' => null,
            ];
        } else {
            $rows[] = $this->cacheRow('Full page cache', 'page_cache', $var . '/page_cache');
        }

        $rows[] = $this->sessionRow();

        $search = $this->searchConfig->get();
        $rows[] = [
            'subsystem' => 'Catalog search',
            'backend' => $search['engine_label'],
            'target' => $search['host'] . ':' . $search['port']
                . (count($search['servers']) > 1 ? ' +' . (count($search['servers']) - 1) . ' more' : ''),
            'service' => 'search:' . $search['host'] . ':' . $search['port'],
        ];

        $amqp = (array)$this->deploymentConfig->get('queue/amqp', []);
        if (!empty($amqp['host'])) {
            $id = $amqp['host'] . ':' . ($amqp['port'] ?? 5672);
            $rows[] = [
                'subsystem' => 'Message queue',
                'backend' => 'RabbitMQ',
                'target' => $id . ' vhost ' . ($amqp['virtualhost'] ?? '/'),
                'service' => 'rabbitmq:' . $id,
            ];
        } else {
            $rows[] = ['subsystem' => 'Message queue', 'backend' => 'MySQL', 'target' => 'queue_message table', 'service' => 'mysql'];
        }

        $lock = (string)$this->deploymentConfig->get('lock/provider', 'db');
        if ($lock === 'file') {
            $rows[] = $this->fileRow('Locks', 'File', (string)$this->deploymentConfig->get(
                'lock/config/path',
                $var . '/locks'
            ));
        } else {
            $rows[] = [
                'subsystem' => 'Locks',
                'backend' => $lock === 'db' ? 'MySQL' : ucfirst($lock),
                'target' => '',
                'service' => $lock === 'db' ? 'mysql' : null,
            ];
        }

        $driver = (string)$this->deploymentConfig->get('remote_storage/driver', 'file');
        $rows[] = $driver === 'file'
            ? $this->fileRow('Media storage', 'File', $this->directoryList->getPath(DirectoryList::MEDIA))
            : [
                'subsystem' => 'Media storage',
                'backend' => strtoupper($driver),
                'target' => (string)$this->deploymentConfig->get('remote_storage/config/bucket', ''),
                'service' => null,
            ];

        foreach ($this->providers as $provider) {
            if (!$provider instanceof WiringProviderInterface) {
                continue;
            }
            try {
                foreach ($provider->getRows() as $row) {
                    if (isset($row['subsystem'], $row['backend'])) {
                        $rows[] = [
                            'subsystem' => (string)$row['subsystem'],
                            'backend' => (string)$row['backend'],
                            'target' => (string)($row['target'] ?? ''),
                            'service' => isset($row['service']) ? (string)$row['service'] : null,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // A broken third-party provider must not take the page down.
                continue;
            }
        }

        return $rows;
    }

    private function cacheRow(string $subsystem, string $frontend, string $defaultPath): array
    {
        $target = $this->redisTargets->cache($frontend);
        if ($target) {
            return [
                'subsystem' => $subsystem,
                'backend' => 'Redis' . ($target['l2'] ? ' (L2)' : ''),
                'target' => $target['id'] . ' db ' . $target['database'],
                'service' => 'redis:' . $target['id'],
            ];
        }
        $backend = (string)$this->deploymentConfig->get("cache/frontend/$frontend/backend", '');
        if ($backend !== '') {
            return [
                'subsystem' => $subsystem,
                'backend' => basename(str_replace(['\\', '_'], '/', $backend)),
                'target' => '',
                'service' => null,
            ];
        }
        $dir = (string)$this->deploymentConfig->get(
            "cache/frontend/$frontend/backend_options/cache_dir",
            $defaultPath
        );
        if ($dir !== '' && $dir[0] !== '/') {
            $dir = $this->directoryList->getPath(DirectoryList::VAR_DIR) . '/' . $dir;
        }
        return $this->fileRow($subsystem, 'File', $dir);
    }

    private function sessionRow(): array
    {
        $save = (string)$this->deploymentConfig->get('session/save', 'files');
        if ($save === 'redis') {
            $target = $this->redisTargets->session();
            return [
                'subsystem' => 'Sessions',
                'backend' => 'Redis',
                'target' => $target ? $target['id'] . ' db ' . $target['database'] : '',
                'service' => $target ? 'redis:' . $target['id'] : 'redis',
            ];
        }
        if ($save === 'db') {
            return ['subsystem' => 'Sessions', 'backend' => 'MySQL', 'target' => 'session table', 'service' => 'mysql'];
        }
        if ($save !== 'files') {
            return ['subsystem' => 'Sessions', 'backend' => ucfirst($save), 'target' => '', 'service' => null];
        }
        $path = (string)$this->deploymentConfig->get('session/save_path', '');
        return $this->fileRow('Sessions', 'Files', $path !== '' ? $path : $this->directoryList->getPath(DirectoryList::SESSION));
    }

    private function fileRow(string $subsystem, string $backend, string $path): array
    {
        $storage = $this->storageLocator->describe($path);
        $root = rtrim($this->directoryList->getRoot(), '/') . '/';
        $shown = str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;

        return [
            'subsystem' => $subsystem,
            'backend' => $backend,
            'target' => $shown . ', ' . $this->where($storage),
            'service' => null,
            'storage' => $storage,
        ];
    }

    private function where(array $storage): string
    {
        return match ($storage['kind']) {
            StorageLocator::KIND_SHARED => 'shared ' . $storage['label'] . ' mount',
            StorageLocator::KIND_LOCAL => 'this node only',
            default => 'storage type unknown',
        };
    }
}
