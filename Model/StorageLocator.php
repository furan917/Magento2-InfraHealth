<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Model;

/**
 * Local disk or shared mount for a path, from /proc/self/mountinfo with a stat() fallback.
 */
class StorageLocator
{
    public const KIND_LOCAL = 'local';
    public const KIND_SHARED = 'shared';
    public const KIND_UNKNOWN = 'unknown';

    /** Filesystem types that are network or cluster mounts, with a friendly name. */
    private const SHARED_TYPES = [
        'nfs' => 'NFS', 'nfs4' => 'NFS', 'cifs' => 'SMB/CIFS', 'smb3' => 'SMB/CIFS', 'smbfs' => 'SMB/CIFS',
        'glusterfs' => 'GlusterFS', 'fuse.glusterfs' => 'GlusterFS', 'ceph' => 'CephFS', 'fuse.ceph' => 'CephFS',
        'fuse.ceph-fuse' => 'CephFS', 'lustre' => 'Lustre', 'gpfs' => 'IBM Storage Scale', 'beegfs' => 'BeeGFS',
        'ocfs2' => 'OCFS2', 'gfs2' => 'GFS2', 'efs' => 'Amazon EFS', 'fuse.s3fs' => 'S3 (s3fs)',
        'fuse.goofys' => 'S3 (goofys)', 'fuse.mount-s3' => 'S3 (Mountpoint)', 'fuse.juicefs' => 'JuiceFS',
        'fuse.gcsfuse' => 'Cloud Storage (gcsfuse)', 'fuse.rclone' => 'rclone', 'fuse.sshfs' => 'SSHFS',
        'fuse.blobfuse' => 'Azure Blob (blobfuse)', 'fuse.blobfuse2' => 'Azure Blob (blobfuse)',
        'virtiofs' => 'Host share (virtiofs)', '9p' => 'Host share (9p)', 'fuse.grpcfuse' => 'Host share (gRPC FUSE)',
        'fakeowner' => 'Host share (Docker Desktop)', 'drvfs' => 'Host share (WSL)',
    ];

    private ?array $mounts = null;

    /**
     * @return array{kind: string, label: string, fstype: ?string, mount: ?string, source: ?string, method: string}
     */
    public function describe(string $path): array
    {
        $path = $this->existing($path);
        $mount = $path === null ? null : $this->mountFor($path);

        if ($mount !== null) {
            $type = $mount['fstype'];
            if (isset(self::SHARED_TYPES[$type])) {
                $label = self::SHARED_TYPES[$type];
                $label = $this->providerLabel($label, $mount['source']);
                return $this->result(self::KIND_SHARED, $label, $mount, 'mountinfo');
            }
            $label = match ($type) {
                'overlay', 'aufs' => 'Container filesystem',
                'tmpfs' => 'Memory (tmpfs)',
                default => 'Local disk',
            };
            return $this->result(self::KIND_LOCAL, $label . ' (' . $type . ')', $mount, 'mountinfo');
        }

        // Major device 0 means no local block device (Linux encoding only).
        $stat = $path === null || PHP_OS_FAMILY !== 'Linux' ? false : @stat($path);
        if ($stat === false) {
            return $this->result(self::KIND_UNKNOWN, 'Unknown storage', null, 'none');
        }
        $dev = (int)$stat['dev'];
        $major = (($dev >> 8) & 0xfff) | (($dev >> 32) & ~0xfff);
        return $major === 0
            ? $this->result(self::KIND_UNKNOWN, 'Network or virtual filesystem', null, 'stat')
            : $this->result(self::KIND_LOCAL, 'Local disk', null, 'stat');
    }

    /**
     * Name the managed service behind a network mount where its hostname gives it away.
     * (EFS mounted with TLS via efs-utils shows as 127.0.0.1 and stays plain "NFS".)
     */
    private function providerLabel(string $label, string $source): string
    {
        return match (true) {
            (bool)preg_match('/\.efs\.[a-z0-9-]+\.amazonaws\.com|^fs-[0-9a-f]{8,}/', $source) => 'Amazon EFS',
            (bool)preg_match('/\.fsx\.[a-z0-9-]+\.amazonaws\.com/', $source) => 'Amazon FSx',
            (bool)preg_match('/\.file\.core\.(windows\.net|chinacloudapi\.cn|usgovcloudapi\.net)/', $source) => 'Azure Files',
            (bool)preg_match('/\.netapp\.|anf\./', $source) => $label . ' (NetApp)',
            default => $label,
        };
    }

    private function result(string $kind, string $label, ?array $mount, string $method): array
    {
        return [
            'kind' => $kind,
            'label' => $label,
            'fstype' => $mount['fstype'] ?? null,
            'mount' => $mount['point'] ?? null,
            'source' => $mount ? $this->redactSource($mount['source']) : null,
            'method' => $method,
        ];
    }

    /**
     * Drop any user:password@ that some FUSE sources carry.
     */
    private function redactSource(string $source): string
    {
        return (string)preg_replace('#^[^/@\s]+:[^/@\s]*@#', '', $source);
    }

    /**
     * Nearest existing ancestor (e.g. var/session may not exist yet).
     */
    private function existing(string $path): ?string
    {
        while ($path !== '' && $path !== '/' && !@file_exists($path)) {
            $path = dirname($path);
        }
        $real = @realpath($path);
        return $real === false ? null : $real;
    }

    private function mountFor(string $path): ?array
    {
        $best = null;
        foreach ($this->mounts() as $mount) {
            $point = $mount['point'];
            if (($path === $point || $point === '/' || str_starts_with($path, rtrim($point, '/') . '/'))
                && ($best === null || strlen($point) >= strlen($best['point']))) {
                $best = $mount;
            }
        }
        return $best;
    }

    /**
     * @return array<int, array{point: string, fstype: string, source: string}>
     */
    private function mounts(): array
    {
        if ($this->mounts !== null) {
            return $this->mounts;
        }
        $this->mounts = [];
        $raw = @file_get_contents('/proc/self/mountinfo');
        if (!is_string($raw)) {
            return $this->mounts;
        }
        $unescape = static fn (string $s) => preg_replace_callback(
            '/\\\\([0-7]{3})/',
            static fn ($m) => chr((int)octdec($m[1])),
            $s
        );
        foreach (explode("\n", $raw) as $line) {
            $halves = explode(' - ', $line, 2);
            if (count($halves) !== 2) {
                continue;
            }
            $left = explode(' ', $halves[0]);
            $right = explode(' ', $halves[1]);
            if (!isset($left[4], $right[1])) {
                continue;
            }
            $this->mounts[] = [
                'point' => (string)$unescape($left[4]),
                'fstype' => $right[0],
                'source' => (string)$unescape($right[1]),
            ];
        }
        return $this->mounts;
    }
}
