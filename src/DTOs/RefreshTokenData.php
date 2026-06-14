<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for {@see \Glueful\Controllers\AuthController::refreshToken()}.
 *
 * The property name is the EXACT JSON key the endpoint reads — the router's
 * {@see \Glueful\Validation\RequestDataHydrator} binds body keys to constructor
 * params by exact name (no snake↔camel conversion), so this stays snake_case.
 *
 * A missing/blank `refresh_token` is rejected by the #[Rule] before the
 * controller runs, producing a {@see \Glueful\Validation\ValidationException}
 * (HTTP 422) — matching the controller's previous manual guard.
 */
final class RefreshTokenData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $refresh_token,
    ) {
    }
}
