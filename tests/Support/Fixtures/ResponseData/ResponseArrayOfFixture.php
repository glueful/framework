<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Fixtures\ResponseData;

use Glueful\Http\Contracts\ResponseData;
use Glueful\Validation\Attributes\ArrayOf;

final class ResponseArrayOfFixture implements ResponseData
{
    public function __construct(
        #[ArrayOf(ResponseFieldFixture::class)]
        public readonly array $schema = [],
    ) {
    }
}
