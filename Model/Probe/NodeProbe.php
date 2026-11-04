<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Model\Probe;

use Furan\InfraHealth\Model\Platform;
use Furan\InfraHealth\Api\ProbeInterface;
use Furan\InfraHealth\Model\ProbeResult;
use Magento\Framework\App\State;

/**
 * The app node that served this request. Behind a load balancer every poll may land on a different node.
 */
class NodeProbe implements ProbeInterface
{
    public function __construct(
        private State $appState,
        private Platform $platform
    ) {
    }

    public function getLabel(): string
    {
        return 'App node';
    }

    public function run(): array
    {
        $host = (string)gethostname();
        $result = new ProbeResult('node', 'App node', $host);
        $result->setSummary(sprintf('PHP %s (%s)', PHP_VERSION, PHP_SAPI));
        $platform = $this->platform->platform();
        $containerised = !in_array($platform['name'], ['Linux host or VM'], true) && PHP_OS_FAMILY === 'Linux';
        $result->addConfig('Hostname', $host);
        $result->addConfig('Platform', $platform['name']);
        $result->addConfig('OS', $this->platform->os());
        $result->addConfig('PHP', PHP_VERSION . ' (' . PHP_SAPI . ')');
        $result->addConfig('Magento mode', $this->appState->getMode());
        $result->addConfig('Memory limit', (string)ini_get('memory_limit'));
        $result->addConfig('Max execution', ini_get('max_execution_time') . 's');

        // In a container, load and core count are the host's.
        $hostPrefix = $containerised ? 'Host ' : '';
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
        if ($load) {
            $result->addStat('load1', $hostPrefix . 'load (1m)', round($load[0], 2));
            $result->addStat('load5', $hostPrefix . 'load (5m)', round($load[1], 2));
        }
        $result->addStat('cpus', $hostPrefix . 'CPU cores', $this->platform->hostCpus());
        $cpuLimit = $this->platform->cpuLimit();
        if ($cpuLimit !== null) {
            $result->addStat('cpu_limit', 'CPU limit (cores)', $cpuLimit);
        }
        $memory = $this->platform->memory();
        if ($memory !== null) {
            $result->addStat('mem_used', $containerised ? 'Container memory' : 'Memory in use', $memory['used'], 'bytes');
            if ($memory['limit']) {
                $result->addGauge(
                    'Memory limit',
                    $memory['used'] / $memory['limit'] * 100,
                    round($memory['used'] / 1048576) . ' of ' . round($memory['limit'] / 1048576) . ' MB'
                );
                if ($memory['used'] / $memory['limit'] > 0.9) {
                    $result->degrade(ProbeResult::STATUS_WARN);
                }
            }
        }
        $result->addStat('peak', 'Request peak memory', memory_get_peak_usage(true), 'bytes');

        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        if (!$opcache) {
            $result->addConfig('OPcache', PHP_SAPI === 'cli' ? 'disabled for CLI' : 'disabled');
            if (PHP_SAPI !== 'cli') {
                $result->degrade(ProbeResult::STATUS_WARN);
            }
            return [$result];
        }

        $memory = (array)($opcache['memory_usage'] ?? []);
        $stats = (array)($opcache['opcache_statistics'] ?? []);
        $used = (int)($memory['used_memory'] ?? 0);
        $total = $used + (int)($memory['free_memory'] ?? 0) + (int)($memory['wasted_memory'] ?? 0);
        $keysUsed = (int)($stats['num_cached_keys'] ?? 0);
        $keysMax = (int)($stats['max_cached_keys'] ?? 0);

        $result->addConfig('OPcache', 'enabled');
        $result->addConfig('JIT', !empty($opcache['jit']['enabled']) ? 'on' : 'off');
        $result->addStat('scripts', 'Cached scripts', (int)($stats['num_cached_scripts'] ?? 0));
        $result->addGauge('OPcache hit rate', (float)($stats['opcache_hit_rate'] ?? 0), '', true);
        $result->addGauge(
            'OPcache memory',
            $total ? $used / $total * 100 : null,
            round($used / 1048576) . ' of ' . round($total / 1048576) . ' MB'
        );
        $result->addGauge('OPcache keys', $keysMax ? $keysUsed / $keysMax * 100 : null, $keysUsed . ' of ' . $keysMax);

        if (!empty($opcache['cache_full']) || ($total && $used / $total > 0.95)) {
            $result->degrade(ProbeResult::STATUS_WARN);
        }
        return [$result];
    }
}
