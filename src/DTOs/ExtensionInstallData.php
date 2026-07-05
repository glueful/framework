<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for the extensions install endpoint.
 *
 * The router hydrates + validates this before the controller runs; a missing/blank
 * `package` is a 422 here. The strict allowlist (vendor prefix, name grammar,
 * catalog membership) runs later in {@see \Glueful\Extensions\Install\ExtensionInstaller}
 * because it needs the live catalog.
 */
final class ExtensionInstallData implements RequestData
{
    public function __construct(
        #[Rule('required|string|max:150')]
        public string $package,
    ) {
    }
}
