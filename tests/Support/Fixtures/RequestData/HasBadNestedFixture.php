<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Fixtures\RequestData;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class HasBadNestedFixture implements RequestData
{
    /** @param array<int,BadNestedSourceFixture> $rows */
    public function __construct(
        #[ArrayOf(BadNestedSourceFixture::class)] #[Rule('array')] public array $rows = [],
    ) {
    }
}
