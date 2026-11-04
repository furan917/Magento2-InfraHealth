<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Model\Probe;

use Furan\InfraHealth\Model\HttpJson;
use Furan\InfraHealth\Api\ProbeInterface;
use Furan\InfraHealth\Model\ProbeResult;
use Furan\InfraHealth\Model\SearchConfig;

/**
 * OpenSearch / Elasticsearch via read-only GETs. Read/write latency comes from the
 * engine's own search/indexing counters, so no test documents are ever written.
 */
class SearchProbe implements ProbeInterface
{
    private const MAX_INDEX_ROWS = 15;

    public function __construct(
        private SearchConfig $searchConfig,
        private HttpJson $http
    ) {
    }

    public function getLabel(): string
    {
        return $this->searchConfig->get()['engine_label'];
    }

    public function run(): array
    {
        $cfg = $this->searchConfig->get();
        $id = $cfg['host'] . ':' . $cfg['port'];
        $result = new ProbeResult('search', $cfg['engine_label'], $id);
        $result->addConfig(count($cfg['servers']) > 1 ? 'Servers' : 'Endpoint', implode(', ', $cfg['servers']));
        $result->addConfig('Engine setting', $cfg['engine']);
        $result->addConfig('Index prefix', $cfg['prefix']);
        $result->addConfig('Auth', $cfg['username'] !== '' ? 'set' : 'none');
        if (!$cfg['verify_tls']) {
            $result->addConfig('TLS certificate check', 'disabled in config');
        }

        // ElasticSuite may list several servers; use the first that answers.
        $baseUrl = null;
        $root = null;
        $errors = [];
        foreach ($cfg['servers'] as $server) {
            try {
                [$root, $first] = $this->http->get($server . '/', $cfg['username'], $cfg['password'], $cfg['verify_tls']);
                $baseUrl = $server;
                break;
            } catch (\Throwable $e) {
                $errors[] = $server . ': ' . $e->getMessage();
            }
        }
        if ($baseUrl === null) {
            return [$result->fail(implode('; ', $errors))];
        }
        if (count($cfg['servers']) > 1) {
            $result->addConfig('Answered by', $baseUrl);
            if ($errors) {
                $result->degrade(ProbeResult::STATUS_WARN);
                $result->addConfig('Unreachable', implode('; ', $errors));
            }
        }

        $get = fn (string $path) => $this->http->get($baseUrl . $path, $cfg['username'], $cfg['password'], $cfg['verify_tls']);

        try {
            $latencies = [$first];
            for ($i = 0; $i < 2; $i++) {
                [, $latencies[]] = $get('/');
            }
            $result->setLatency(array_sum($latencies) / count($latencies));
        } catch (\Throwable $e) {
            return [$result->fail($e)];
        }

        // Managed clusters may deny cluster-level reads; treat them as optional.
        $limited = [];
        $optional = function (string $label, string $path) use ($get, &$limited): array {
            try {
                return (array)$get($path)[0];
            } catch (\Throwable $e) {
                $limited[] = $label . ' (' . $e->getMessage() . ')';
                return [];
            }
        };
        $health = $optional('cluster health', '/_cluster/health');
        $indices = $optional('index list', '/_cat/indices/' . rawurlencode($cfg['prefix']) . '*?format=json&bytes=b'
            . '&h=index,health,docs.count,store.size&s=index');
        $nodes = $optional('node stats', '/_nodes/stats/indices,jvm?filter_path=nodes.*.name,nodes.*.indices.search,'
            . 'nodes.*.indices.indexing,nodes.*.indices.docs,nodes.*.jvm.mem.heap_used_percent');
        if ($limited) {
            $result->addConfig('Not readable', implode('; ', $limited));
        }

        $clusterStatus = (string)($health['status'] ?? 'unknown');
        // On one data node replicas can never be placed, so yellow is expected.
        $dataNodes = (int)($health['number_of_data_nodes'] ?? $health['number_of_nodes'] ?? 0);
        $expectedYellow = $clusterStatus === 'yellow' && $dataNodes === 1;
        $result->degrade(match (true) {
            in_array($clusterStatus, ['green', 'unknown'], true) => ProbeResult::STATUS_OK,
            $expectedYellow => ProbeResult::STATUS_TIP,
            $clusterStatus === 'yellow' => ProbeResult::STATUS_WARN,
            default => ProbeResult::STATUS_DOWN,
        });
        $result->setSummary(sprintf(
            '%s %s, cluster %s, %d node%s',
            $root['version']['distribution'] ?? 'elasticsearch',
            $root['version']['number'] ?? '?',
            $clusterStatus,
            (int)($health['number_of_nodes'] ?? 0),
            (int)($health['number_of_nodes'] ?? 0) === 1 ? '' : 's'
        ));
        if ($expectedYellow) {
            $unplaced = (int)($health['unassigned_shards'] ?? 0);
            $result->addTip(sprintf(
                'This is a single-node cluster, so %d replica shard%s can\'t be placed and the cluster reports yellow.'
                . ' Searches are unaffected. Setting number_of_replicas to 0 on these indices would turn it green,'
                . ' or add a second node if you want redundancy.',
                $unplaced,
                $unplaced === 1 ? '' : 's'
            ));
        }
        $result->addConfig('Cluster', $health['cluster_name'] ?? '?');
        $result->addConfig('Version', $root['version']['number'] ?? '?');

        $search = ['query_total' => 0, 'query_time_in_millis' => 0];
        $indexing = ['index_total' => 0, 'index_time_in_millis' => 0];
        $heap = [];
        foreach ((array)($nodes['nodes'] ?? []) as $node) {
            foreach ($search as $k => $_) {
                $search[$k] += (int)($node['indices']['search'][$k] ?? 0);
            }
            foreach ($indexing as $k => $_) {
                $indexing[$k] += (int)($node['indices']['indexing'][$k] ?? 0);
            }
            $heap[] = (float)($node['jvm']['mem']['heap_used_percent'] ?? 0);
        }

        $docs = 0;
        $bytes = 0;
        $rows = [];
        $flags = [];
        foreach ((array)$indices as $index) {
            $docs += (int)($index['docs.count'] ?? 0);
            $bytes += (int)($index['store.size'] ?? 0);
            if (count($rows) < self::MAX_INDEX_ROWS) {
                $rows[] = [
                    $index['index'] ?? '?',
                    $index['health'] ?? '?',
                    (int)($index['docs.count'] ?? 0),
                    ['bytes' => (int)($index['store.size'] ?? 0)],
                ];
                $flags[] = match ($index['health'] ?? '') {
                    'green' => '',
                    'yellow' => $expectedYellow ? '' : 'warn',
                    default => 'down',
                };
            }
        }

        $result->addStat('docs', 'Documents', $docs);
        $result->addStat('size', 'Store size', $bytes, 'bytes');
        $result->addStat('indices', 'Indices', count((array)$indices));
        $result->addStat(
            'read_avg',
            'Avg query (lifetime)',
            $search['query_total'] ? round($search['query_time_in_millis'] / $search['query_total'], 2) : null,
            'ms'
        );
        $result->addStat(
            'write_avg',
            'Avg index op (lifetime)',
            $indexing['index_total'] ? round($indexing['index_time_in_millis'] / $indexing['index_total'], 2) : null,
            'ms'
        );
        $result->addStat(
            'shards_unassigned',
            $expectedYellow ? 'Unplaced replicas' : 'Unassigned shards',
            (int)($health['unassigned_shards'] ?? 0)
        );

        if ($expectedYellow) {
            // Primaries are what serve searches; on one node they are the meaningful figure.
            $result->addGauge('Primary shards active', 100.0, ($health['active_primary_shards'] ?? 0) . ' serving', true);
        } elseif ($health) {
            $result->addGauge(
                'Active shards',
                (float)($health['active_shards_percent_as_number'] ?? 0),
                ($health['active_shards'] ?? 0) . ' active',
                true
            );
        }
        $result->addGauge('JVM heap', $heap ? max($heap) : null, $heap ? 'busiest node' : '');

        $result->addRate('read_now', 'Query latency (now)', 'query_ms', 'query_total', 'ms');
        $result->addRate('write_now', 'Index latency (now)', 'index_ms', 'index_total', 'ms');
        $result->addRate('qrate', 'Queries / sec (now)', 'query_total');
        $result->setCounters([
            'query_total' => $search['query_total'],
            'query_ms' => $search['query_time_in_millis'],
            'index_total' => $indexing['index_total'],
            'index_ms' => $indexing['index_time_in_millis'],
        ]);
        $result->addTable('Indices matching ' . $cfg['prefix'] . '*', ['Index', 'Health', 'Docs', 'Size'], $rows, $flags);

        return [$result];
    }
}
