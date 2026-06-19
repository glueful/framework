<?php

declare(strict_types=1);

namespace Glueful\Installer;

final class InstallStep
{
    public const OK = 'ok';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';
    public const WARNING = 'warning';

    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly string $message = '',
    ) {
    }
}
