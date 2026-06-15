<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Fixtures\RequestData;

use Glueful\Validation\Attributes\FromRoute;
use Glueful\Validation\Contracts\RequestData;

final class BadNestedSourceFixture implements RequestData
{
    public function __construct(
        #[FromRoute] public string $oops = '',
    ) {
    }
}
