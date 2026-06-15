<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Fixtures\RequestData;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class NestedArrayFixture implements RequestData
{
    /** @param array<int,FieldDefFixture> $schema */
    public function __construct(
        #[Rule('required|string')] public string $slug = '',
        #[ArrayOf(FieldDefFixture::class)] #[Rule('required|array')] public array $schema = [],
    ) {
    }
}
