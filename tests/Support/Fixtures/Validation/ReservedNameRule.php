<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Fixtures\Validation;

use Glueful\Validation\Contracts\Rule;

final class ReservedNameRule implements Rule
{
    public function validate(mixed $value, array $context = []): ?string
    {
        return $value === 'admin' ? 'That name is reserved.' : null;
    }
}
