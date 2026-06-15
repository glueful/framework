<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Fixtures\RequestData;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\FromRoute;
use Glueful\Validation\Contracts\RequestData;

final class DualSourceFixture implements RequestData
{
    public function __construct(
        #[FromRoute] #[FromQuery] public string $x = '',
    ) {
    }
}
