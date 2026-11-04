<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Model;

/**
 * OS, container platform and cgroup CPU/memory limits, read from local files.
 */
class Platform
{
    /** cgroup v1 reports "no limit" as a huge page-aligned number. */
    private const CGROUP_V1_UNLIMITED = 0x7FFFFFFFFFFFF000;

    public function os(): string
    {
        $release = $this->read('/etc/os-release');
        if ($release !== null && preg_match('/^PRETTY_NAME="?([^"\n]+)"?/m', $release, $m)) {
            return $m[1];
        }
        return PHP_OS_FAMILY . ' ' . php_uname('r');
    }

    /**
     * @return array{name: string, hint: string}
     */
    public function platform(): array
    {
        $cgroup = (string)$this->read('/proc/self/cgroup');
        $env = static fn (string $name) => (string)(getenv($name) ?: ($_SERVER[$name] ?? ''));

        if ($env('AWS_EXECUTION_ENV') === 'AWS_ECS_FARGATE') {
            return ['name' => 'AWS ECS on Fargate', 'hint' => 'AWS_EXECUTION_ENV'];
        }
        if ($env('ECS_CONTAINER_METADATA_URI_V4') !== '' || $env('ECS_CONTAINER_METADATA_URI') !== ''
            || str_contains($cgroup, '/ecs/')) {
            return ['name' => 'AWS ECS', 'hint' => $cgroup !== '' && str_contains($cgroup, '/ecs/') ? 'cgroup path' : 'ECS env'];
        }
        if ($env('KUBERNETES_SERVICE_HOST') !== '' || str_contains($cgroup, 'kubepods')
            || @is_dir('/var/run/secrets/kubernetes.io/serviceaccount')) {
            return ['name' => 'Kubernetes', 'hint' => 'service account / cgroup'];
        }
        if ($env('WEBSITE_SITE_NAME') !== '') {
            return ['name' => 'Azure App Service', 'hint' => 'WEBSITE_SITE_NAME'];
        }
        if (@file_exists('/.dockerenv') || str_contains($cgroup, 'docker') || str_contains($cgroup, 'containerd')
            || @file_exists('/run/.containerenv')) {
            return ['name' => 'Container', 'hint' => '/.dockerenv or cgroup'];
        }
        if (PHP_OS_FAMILY !== 'Linux') {
            return ['name' => PHP_OS_FAMILY . ' host', 'hint' => 'PHP_OS_FAMILY'];
        }
        return ['name' => 'Linux host or VM', 'hint' => 'no container markers'];
    }

    /**
     * CPU cores visible to the host (not the container limit).
     */
    public function hostCpus(): ?int
    {
        $info = $this->read('/proc/cpuinfo');
        if ($info !== null) {
            $count = preg_match_all('/^processor\s*:/m', $info);
            return $count ?: null;
        }
        return null;
    }

    /**
     * CPU limit in cores from cgroup quota, or null when unlimited/unknown.
     */
    public function cpuLimit(): ?float
    {
        $v2 = $this->read('/sys/fs/cgroup/cpu.max');
        if ($v2 !== null) {
            [$quota, $period] = array_pad(preg_split('/\s+/', trim($v2)), 2, '100000');
            return $quota === 'max' || (int)$period <= 0 ? null : round((int)$quota / (int)$period, 2);
        }
        $quota = $this->read('/sys/fs/cgroup/cpu/cpu.cfs_quota_us') ?? $this->read('/sys/fs/cgroup/cpu,cpuacct/cpu.cfs_quota_us');
        $period = $this->read('/sys/fs/cgroup/cpu/cpu.cfs_period_us') ?? $this->read('/sys/fs/cgroup/cpu,cpuacct/cpu.cfs_period_us');
        if ($quota === null || $period === null || (int)$quota <= 0 || (int)$period <= 0) {
            return null;
        }
        return round((int)$quota / (int)$period, 2);
    }

    /**
     * Container memory in use (excluding reclaimable page cache) and its limit, in bytes.
     *
     * @return array{used: int, limit: ?int}|null
     */
    public function memory(): ?array
    {
        $current = $this->read('/sys/fs/cgroup/memory.current');
        if ($current !== null) {
            $max = trim((string)$this->read('/sys/fs/cgroup/memory.max'));
            $inactive = $this->statValue((string)$this->read('/sys/fs/cgroup/memory.stat'), 'inactive_file');
            return [
                'used' => max(0, (int)$current - $inactive),
                'limit' => $max === '' || $max === 'max' ? null : (int)$max,
            ];
        }
        $usage = $this->read('/sys/fs/cgroup/memory/memory.usage_in_bytes');
        if ($usage === null) {
            return null;
        }
        $limit = (int)$this->read('/sys/fs/cgroup/memory/memory.limit_in_bytes');
        $inactive = $this->statValue((string)$this->read('/sys/fs/cgroup/memory/memory.stat'), 'total_inactive_file');
        return [
            'used' => max(0, (int)$usage - $inactive),
            'limit' => $limit <= 0 || $limit >= self::CGROUP_V1_UNLIMITED ? null : $limit,
        ];
    }

    private function statValue(string $stat, string $key): int
    {
        return preg_match('/^' . preg_quote($key, '/') . '\s+(\d+)/m', $stat, $m) ? (int)$m[1] : 0;
    }

    private function read(string $path): ?string
    {
        if (PHP_OS_FAMILY !== 'Linux' && str_starts_with($path, '/proc')) {
            return null;
        }
        $value = @file_get_contents($path);
        return is_string($value) ? $value : null;
    }
}
