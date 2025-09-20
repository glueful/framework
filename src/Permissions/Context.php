<?php

declare(strict_types=1);

namespace Glueful\Permissions;

final class Context
{
    public function __construct(
        public ?string $tenantId = null,
        /** @var array<string, mixed> */
        public array $routeParams = [],
        /** @var array<string, mixed> */
        public array $jwtClaims = [],
        /** @var array<string, mixed> */
        public array $extra = [],
    ) {
    }
}
