<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/** Request body for the extensions enable/disable endpoints. */
final class ExtensionToggleData implements RequestData
{
    public function __construct(
        #[Rule('required|string|max:150')]
        public string $package,
    ) {
    }
}
