<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Model;

/**
 * Serialisable outcome of a single probe instance, shaped for the dashboard.
 */
class ProbeResult
{
    public const STATUS_OK = 'ok';
    /** Working fine, but there is an improvement worth knowing about. Never an alert. */
    public const STATUS_TIP = 'tip';
    public const STATUS_WARN = 'warn';
    public const STATUS_DOWN = 'down';
    public const STATUS_OFF = 'off';

    private string $status = self::STATUS_OK;
    private ?float $latencyMs = null;
    private string $summary = '';
    private ?string $error = null;
    private array $config = [];
    private array $stats = [];
    private array $gauges = [];
    private array $tables = [];
    private array $counters = [];
    private array $tips = [];
    private array $rates = [];

    public function __construct(
        private string $code,
        private string $label,
        private string $instance = ''
    ) {
        $this->instance = Redactor::clean($instance);
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Raise status severity without ever lowering it (ok < warn < down).
     */
    public function degrade(string $status): self
    {
        $rank = [self::STATUS_OFF => 0, self::STATUS_OK => 1, self::STATUS_TIP => 2, self::STATUS_WARN => 3, self::STATUS_DOWN => 4];
        if (($rank[$status] ?? 0) > ($rank[$this->status] ?? 0)) {
            $this->status = $status;
        }
        return $this;
    }

    public function setLatency(?float $latencyMs): self
    {
        $this->latencyMs = $latencyMs === null ? null : round($latencyMs, 2);
        return $this;
    }

    public function setSummary(string $summary): self
    {
        $this->summary = Redactor::clean($summary);
        return $this;
    }

    public function addConfig(string $label, string|int|float|null $value): self
    {
        $this->config[] = ['label' => $label, 'value' => Redactor::clean((string)($value ?? '—'))];
        return $this;
    }

    /**
     * A headline number; numeric values get a client-side sparkline.
     */
    public function addStat(string $id, string $label, int|float|string|null $value, string $unit = ''): self
    {
        $this->stats[] = ['id' => $id, 'label' => $label, 'value' => $value, 'unit' => $unit];
        return $this;
    }

    /**
     * A 0-100 ring gauge. $invert marks gauges where high is good (e.g. hit ratio).
     */
    public function addGauge(string $label, ?float $percent, string $caption = '', bool $highIsGood = false): self
    {
        $this->gauges[] = [
            'label' => $label,
            'value' => $percent === null ? null : round(max(0.0, min(100.0, $percent)), 1),
            'caption' => $caption,
            'high_is_good' => $highIsGood,
        ];
        return $this;
    }

    /**
     * @param string[] $columns
     * @param array<int, array<int, mixed>> $rows
     * @param array<int, string> $rowFlags Optional per-row status (warn/down) for highlighting
     */
    public function addTable(string $title, array $columns, array $rows, array $rowFlags = []): self
    {
        $this->tables[] = ['title' => $title, 'columns' => $columns, 'rows' => $rows, 'flags' => $rowFlags];
        return $this;
    }

    /**
     * Monotonic counters the browser diffs between polls to get "recent" rates.
     *
     * @param array<string, int|float> $counters
     */
    public function setCounters(array $counters): self
    {
        $this->counters = $counters;
        return $this;
    }

    /**
     * A suggestion shown calmly on the panel; lifts an OK status to "tip" but never higher.
     */
    public function addTip(string $text): self
    {
        $this->tips[] = Redactor::clean($text);
        return $this->degrade(self::STATUS_TIP);
    }

    /**
     * Live figure from setCounters(): a per-second rate, or a ratio when $denominator is set.
     */
    public function addRate(
        string $id,
        string $label,
        string $numerator,
        ?string $denominator = null,
        string $unit = '',
        float $scale = 1.0
    ): self {
        $this->rates[] = [
            'id' => $id,
            'label' => $label,
            'num' => $numerator,
            'den' => $denominator,
            'unit' => $denominator === null && $unit === '' ? '/s' : $unit,
            'scale' => $scale,
        ];
        return $this;
    }

    public function fail(\Throwable|string $error): self
    {
        $this->status = self::STATUS_DOWN;
        $this->error = Redactor::clean($error instanceof \Throwable ? $error->getMessage() : $error);
        return $this;
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'instance' => $this->instance,
            'status' => $this->status,
            'latency_ms' => $this->latencyMs,
            'summary' => $this->summary,
            'error' => $this->error,
            'config' => $this->config,
            'stats' => $this->stats,
            'gauges' => $this->gauges,
            'tables' => $this->tables,
            'counters' => $this->counters,
            'tips' => $this->tips,
            'rates' => $this->rates,
        ];
    }
}
