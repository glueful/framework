<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Fixtures\RequestData;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * A request DTO that (incorrectly) targets a non-RequestData class with #[ArrayOf].
 * Used to verify the hydrator fails loud rather than silently mishydrating.
 */
final class ArrayOfNonRequestDataFixture implements RequestData
{
    /** @param array<int,NonRequestDataFixture> $items */
    public function __construct(
        #[ArrayOf(NonRequestDataFixture::class)] #[Rule('array')] public array $items = [],
    ) {
    }
}
