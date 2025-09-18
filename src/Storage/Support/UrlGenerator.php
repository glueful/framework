<?php

declare(strict_types=1);

namespace Glueful\Storage\Support;

use Glueful\Storage\PathGuard;

class UrlGenerator
{
    /** @var array<string, mixed> */
    private array $config;
    private PathGuard $pathGuard;

    /**
     * @param array<string,mixed> $storageConfig Storage config with 'default' and 'disks'
     */
    public function __construct(array $storageConfig, PathGuard $pathGuard)
    {
        $this->config = $storageConfig;
        $this->pathGuard = $pathGuard;
    }

    /**
     * Generate a public URL for a file on a given disk.
     * Uses 'base_url' (or 'cdn_base_url') from disk config if present.
     */
    public function url(string $path, ?string $disk = null): string
    {
        $path = $this->pathGuard->validate($path);
        $diskName = $disk ?? (string)($this->config['default'] ?? 'local');

        $diskConfig = $this->config['disks'][$diskName] ?? null;
        if (!is_array($diskConfig)) {
            // If disk not configured, return the path as-is
            return $path;
        }

        $base = (string)($diskConfig['cdn_base_url'] ?? $diskConfig['base_url'] ?? '');
        if ($base === '') {
            return $path;
        }

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Expose raw disk configuration to callers that need adapter-specific data
     * (e.g., S3 bucket for signed URLs). Returns empty array if missing.
     *
     * @return array<string, mixed>
     */
    public function diskConfig(string $disk): array
    {
        $diskConfig = $this->config['disks'][$disk] ?? [];
        return is_array($diskConfig) ? $diskConfig : [];
    }
}
