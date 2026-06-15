<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Fixtures\ResponseData;

use Glueful\Http\Contracts\ResponseData;

final class ResponseFieldFixture implements ResponseData
{
    public function __construct(
        public readonly string $name = '',
        public readonly string $type = '',
    ) {
    }
}
