<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Fixtures\RequestData;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class ScalarArrayFixture implements RequestData
{
    /** @param array<int,int> $ids */
    public function __construct(
        #[ArrayOf('int')] #[Rule('required|array')] public array $ids = [],
    ) {
    }
}
