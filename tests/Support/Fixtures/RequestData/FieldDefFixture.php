<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Fixtures\RequestData;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class FieldDefFixture implements RequestData
{
    public function __construct(
        #[Rule('required|string')] public string $name = '',
        #[Rule('required|string')] public string $type = '',
    ) {
    }
}
