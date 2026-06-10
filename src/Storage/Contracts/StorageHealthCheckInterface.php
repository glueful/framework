<?php

declare(strict_types=1);

namespace Glueful\Storage\Contracts;

interface StorageHealthCheckInterface
{
    /**
     * @param array<string, mixed> $diskConfig
     * @return array{ok: bool, message: string, details?: array<string, mixed>}
     */
    public function check(string $disk, array $diskConfig): array;
}
