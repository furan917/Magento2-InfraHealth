<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Api;

use Furan\InfraHealth\Model\ProbeResult;

/**
 * Read-only health check. Keep it bounded by short timeouts and never expose secrets.
 */
interface ProbeInterface
{
    /**
     * Name shown while the probe is still running, e.g. "Varnish".
     */
    public function getLabel(): string;

    /**
     * @return ProbeResult[] One result per discovered instance (e.g. two Redis servers)
     */
    public function run(): array;
}
