<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Model\Probe;

use Furan\InfraHealth\Api\ProbeInterface;
use Furan\InfraHealth\Model\ProbeResult;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\Topology\ConfigInterface as TopologyConfig;
use Magento\Framework\Module\ModuleListInterface;

/**
 * Backlog of queues that run on MySQL (Magento_MysqlMq), with or without RabbitMQ alongside.
 */
class MysqlQueueProbe implements ProbeInterface
{
    // Magento\MysqlMq\Model\QueueManagement statuses; complete and to-be-deleted are skipped.
    private const STATUSES = [2 => 'new', 3 => 'in_progress', 5 => 'retry', 6 => 'error'];
    private const BACKLOG_WARN = 1000;

    public function __construct(
        private ResourceConnection $resource,
        private TopologyConfig $topologyConfig,
        private ModuleListInterface $moduleList
    ) {
    }

    public function getLabel(): string
    {
        return 'MySQL queue';
    }

    /**
     * @return string[]
     */
    public function queueNames(): array
    {
        if (!$this->moduleList->has('Magento_MysqlMq')) {
            return [];
        }
        $names = [];
        foreach ($this->topologyConfig->getQueues() as $queue) {
            if ($queue->getConnection() === 'db') {
                $names[] = (string)$queue->getName();
            }
        }
        sort($names);
        return array_values(array_unique($names));
    }

    public function run(): array
    {
        $names = $this->queueNames();
        $result = new ProbeResult('dbqueue', 'MySQL queue', 'queue_message');
        if (!$names) {
            return [$result->setStatus(ProbeResult::STATUS_OFF)->setSummary('Not used — no queues run on MySQL')];
        }

        $connection = $this->resource->getConnection();
        $start = hrtime(true);
        try {
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from(['s' => $this->resource->getTableName('queue_message_status')], ['status', 'total' => 'COUNT(*)'])
                    ->join(['q' => $this->resource->getTableName('queue')], 'q.id = s.queue_id', ['name'])
                    ->where('s.status IN (?)', array_keys(self::STATUSES))
                    ->group(['q.name', 's.status'])
            );
        } catch (\Throwable $e) {
            return [$result->fail($e)];
        }
        $result->setLatency((hrtime(true) - $start) / 1e6);

        $counts = array_fill_keys($names, array_fill_keys(self::STATUSES, 0));
        foreach ($rows as $row) {
            $counts[$row['name']] ??= array_fill_keys(self::STATUSES, 0);
            $counts[$row['name']][self::STATUSES[(int)$row['status']]] = (int)$row['total'];
        }
        uasort($counts, static fn ($a, $b) => $b['new'] <=> $a['new']);

        $table = [];
        $flags = [];
        foreach ($counts as $name => $c) {
            $table[] = [$name, $c['new'], $c['in_progress'], $c['retry'], $c['error']];
            $flags[] = match (true) {
                $c['new'] >= self::BACKLOG_WARN => 'down',
                $c['error'] > 0 || $c['retry'] > 0 => 'warn',
                default => '',
            };
        }

        $totals = array_fill_keys(self::STATUSES, 0);
        foreach ($counts as $c) {
            foreach ($c as $status => $n) {
                $totals[$status] += $n;
            }
        }

        $result->setSummary(sprintf('%d queue%s on MySQL', count($names), count($names) === 1 ? '' : 's'));
        $result->addConfig('Queues on MySQL', (string)count($names));
        $result->addStat('new', 'Waiting', $totals['new']);
        $result->addStat('in_progress', 'In progress', $totals['in_progress']);
        $result->addStat('retry', 'Retry', $totals['retry']);
        $result->addStat('error', 'Failed', $totals['error']);
        $result->addTable('Queues on MySQL', ['Queue', 'Waiting', 'In progress', 'Retry', 'Failed'], $table, $flags);

        if ($totals['new'] >= self::BACKLOG_WARN) {
            $result->degrade(ProbeResult::STATUS_WARN);
        }
        return [$result];
    }
}
