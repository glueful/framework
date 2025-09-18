<?php
declare(strict_types=1);

namespace Glueful\Config\DTO;

final class QueueConfig
{
    public function __construct(
        public string $connection,
        public int $maxAttempts = 3,
        public bool $retryOnFailure = true,
        public string $strategy = 'immediate',
    ) {}
}
