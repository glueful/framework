<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Fixtures\RequestData;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\FromRoute;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class SourcedFixture implements RequestData
{
    public function __construct(
        #[FromRoute] public string $uuid,
        #[FromQuery] #[Rule('in:draft,published')] public string $status = 'draft',
        #[Rule('required|string')] public string $title = '',
    ) {
    }
}
