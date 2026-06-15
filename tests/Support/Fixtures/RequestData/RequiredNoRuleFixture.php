<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Fixtures\RequestData;

use Glueful\Validation\Contracts\RequestData;

final class RequiredNoRuleFixture implements RequestData
{
    public function __construct(public string $name)
    {
    }
}
