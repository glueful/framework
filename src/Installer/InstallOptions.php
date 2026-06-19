<?php

declare(strict_types=1);

namespace Glueful\Installer;

final class InstallOptions
{
    public function __construct(
        public readonly ?DatabaseConfig $database = null, // null => use existing env / skip db
        public readonly bool $skipDatabase = false,
        public readonly bool $skipKeys = false,
        public readonly bool $skipCache = false,
        public readonly bool $force = false,
    ) {
    }
}
