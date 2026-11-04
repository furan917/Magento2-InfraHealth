<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Model;

use Furan\InfraHealth\Api\ProbeInterface;

/**
 * Runs probes registered in di.xml ("probes", ordered by "sortOrders").
 */
class ProbeRunner
{
    /** @var array<string, ProbeInterface> */
    private array $probes = [];

    /**
     * @param array<string, ProbeInterface|null> $probes
     * @param array<string, int|string> $sortOrders
     */
    public function __construct(
        private Wiring $wiring,
        array $probes = [],
        array $sortOrders = []
    ) {
        foreach ($probes as $code => $probe) {
            // Codes travel in the stats URL and become DOM ids, so keep them simple.
            if ($probe instanceof ProbeInterface && preg_match('/^[a-z][a-z0-9_]{0,31}$/', (string)$code)) {
                $this->probes[(string)$code] = $probe;
            }
        }
        $position = array_flip(array_keys($this->probes));
        uksort($this->probes, static fn ($a, $b) => ((int)($sortOrders[$a] ?? 1000) <=> (int)($sortOrders[$b] ?? 1000))
            ?: $position[$a] <=> $position[$b]);
    }

    /**
     * Probe codes and labels, in display order. Cheap: runs nothing.
     *
     * @return array<int, array{code: string, label: string}>
     */
    public function probes(): array
    {
        $list = [];
        foreach ($this->probes as $code => $probe) {
            $list[] = ['code' => (string)$code, 'label' => $probe->getLabel()];
        }
        return $list;
    }

    public function has(string $code): bool
    {
        return isset($this->probes[$code]);
    }

    /**
     * Config-only description of how subsystems are wired. Cheap: no network calls.
     */
    public function wiring(): array
    {
        return $this->wiring->get();
    }

    /**
     * Run a single probe. Never throws; a crashing probe is reported as down.
     */
    public function runOne(string $code): array
    {
        $started = microtime(true);
        $services = [];
        try {
            foreach ($this->probes[$code]->run() as $result) {
                $services[] = $result->toArray();
            }
        } catch (\Throwable $e) {
            $services[] = (new ProbeResult($code, ucfirst($code)))->fail($e)->toArray();
        }

        return [
            'probe' => $code,
            'generated_at' => time(),
            'duration_ms' => round((microtime(true) - $started) * 1000, 1),
            'node' => $this->node(),
            'services' => $services,
        ];
    }

    /**
     * Every probe in sequence, for the CLI.
     */
    public function run(): array
    {
        $started = microtime(true);
        $services = [];
        foreach (array_keys($this->probes) as $code) {
            array_push($services, ...$this->runOne((string)$code)['services']);
        }

        return [
            'generated_at' => time(),
            'duration_ms' => round((microtime(true) - $started) * 1000, 1),
            'node' => $this->node(),
            'wiring' => $this->wiring(),
            'services' => $services,
        ];
    }

    private function node(): array
    {
        return ['hostname' => (string)gethostname(), 'php' => PHP_VERSION, 'sapi' => PHP_SAPI];
    }
}
