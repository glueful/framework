<?php

declare(strict_types=1);

namespace Glueful\Extensions;

final class ResolverError
{
    public const MISSING_PROVIDER = 'missing_provider';
    public const MISSING_DEPENDENCY = 'missing_dependency';
    public const VERSION_MISMATCH = 'version_mismatch';
    public const DEPENDENCY_CYCLE = 'dependency_cycle';

    public function __construct(
        public readonly string $kind,
        public readonly string $provider,
        public readonly string $message,
    ) {
    }
}
