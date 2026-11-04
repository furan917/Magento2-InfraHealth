<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Model;

/**
 * Tiny helper for averaging round-trip times over a few calls.
 */
class Timer
{
    /**
     * @param callable $call
     * @return float Average milliseconds
     */
    public static function average(callable $call, int $samples = 3): float
    {
        $total = 0.0;
        for ($i = 0; $i < $samples; $i++) {
            $start = hrtime(true);
            $call();
            $total += (hrtime(true) - $start) / 1e6;
        }
        return $total / $samples;
    }
}
