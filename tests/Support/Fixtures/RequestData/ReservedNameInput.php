<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Fixtures\RequestData;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class ReservedNameInput implements RequestData
{
    public function __construct(
        #[Rule('reserved_name')] public string $name = '',
    ) {
    }
}
