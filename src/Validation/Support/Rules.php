<?php

declare(strict_types=1);

namespace Glueful\Validation\Support;

use Glueful\Validation\Validator;
use Glueful\Validation\Contracts\Rule;

/**
 * Sugar factory for building Validators from rules.
 */
final class Rules
{
    /**
     * @param array<string, Rule[]> $rules
     */
    public static function of(array $rules): Validator
    {
        return new Validator($rules);
    }
}
