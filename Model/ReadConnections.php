<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Model;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Module\ModuleListInterface;

/**
 * Database readers from Adobe Commerce (db/slave_connection), Magentiz_SplitDb
 * (db/connection/default/slaves) or Furan_ReadWriteSplit (db/reader_connections).
 */
class ReadConnections
{
    public const MAX_READERS = 8;

    private const SPLITTERS = [
        'commerce' => ['label' => 'Adobe Commerce', 'module' => 'Magento_ResourceConnections'],
        'magentiz' => ['label' => 'Magentiz_SplitDb', 'module' => 'Magentiz_SplitDb'],
        'furan' => ['label' => 'Furan_ReadWriteSplit', 'module' => 'Furan_ReadWriteSplit'],
    ];

    public function __construct(
        private DeploymentConfig $deploymentConfig,
        private ModuleListInterface $moduleList
    ) {
    }

    /**
     * @return array{
     *     readers: array<int, array{name: string, splitter: string, config: array}>,
     *     notes: string[],
     *     switched_off: int,
     *     splitters: string[]
     * }
     */
    public function get(): array
    {
        $readers = [];
        $notes = [];
        $splitters = [];
        $switchedOff = 0;
        $writer = (array)$this->deploymentConfig->get('db/connection/default', []);

        $commerce = (array)$this->deploymentConfig->get('db/slave_connection/default', []);
        if (!empty($commerce['host'])) {
            if ($this->enabled('commerce', $notes, $splitters)) {
                $readers[] = ['name' => 'Reader', 'splitter' => 'commerce', 'config' => $commerce];
            }
        }

        $slaves = (array)($writer['slaves'] ?? []);
        if ($slaves) {
            $splitOn = filter_var($writer['is_split'] ?? false, FILTER_VALIDATE_BOOLEAN)
                && strtolower((string)$this->deploymentConfig->get('DB_MODE', '')) === 'split';
            if (!$splitOn) {
                $notes[] = 'Magentiz_SplitDb readers are configured but split mode is off'
                    . ' (needs is_split => true and DB_MODE => split), so all reads go to the writer.';
            } elseif ($this->enabled('magentiz', $notes, $splitters)) {
                // Slaves inherit unset keys from the writer.
                $base = array_diff_key($writer, array_flip(['slaves', 'is_split', 'persistent']));
                foreach (array_values($slaves) as $i => $slave) {
                    $readers[] = [
                        'name' => 'Reader ' . ($i + 1),
                        'splitter' => 'magentiz',
                        'config' => array_replace($base, (array)$slave),
                    ];
                }
            }
        }

        $furan = (array)$this->deploymentConfig->get('db/reader_connections/default', []);
        if ($furan) {
            if ($this->enabled('furan', $notes, $splitters)) {
                foreach (array_values($furan) as $i => $reader) {
                    if ((string)($reader['active'] ?? '1') !== '1') {
                        $switchedOff++;
                        continue;
                    }
                    $readers[] = ['name' => 'Reader ' . ($i + 1), 'splitter' => 'furan', 'config' => (array)$reader];
                }
            }
        }

        if (count($readers) > self::MAX_READERS) {
            $notes[] = 'Only the first ' . self::MAX_READERS . ' of ' . count($readers) . ' readers are checked.';
            $readers = array_slice($readers, 0, self::MAX_READERS);
        }

        return ['readers' => $readers, 'notes' => $notes, 'switched_off' => $switchedOff, 'splitters' => $splitters];
    }

    public function label(string $splitter): string
    {
        return self::SPLITTERS[$splitter]['label'] ?? $splitter;
    }

    private function enabled(string $splitter, array &$notes, array &$splitters): bool
    {
        $info = self::SPLITTERS[$splitter];
        if ($this->moduleList->has($info['module'])) {
            $splitters[] = $info['label'];
            return true;
        }
        $notes[] = $info['label'] . ' read connections are configured, but ' . $info['module']
            . ' is not enabled, so all reads go to the writer.';
        return false;
    }
}
