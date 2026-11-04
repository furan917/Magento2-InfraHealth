<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Api;

/**
 * Rows for the wiring board. Runs on page render, so config reads only.
 */
interface WiringProviderInterface
{
    /**
     * @return array<int, array{
     *     subsystem: string,
     *     backend: string,
     *     target: string,
     *     service: ?string
     * }> service: "<probe code>[:<instance>]" or null
     */
    public function getRows(): array;
}
