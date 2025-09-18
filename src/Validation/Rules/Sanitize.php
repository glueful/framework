<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;
use Glueful\Validation\Contracts\MutatingRule;

/**
 * Applies simple sanitization functions to scalar/string values.
 * Supported ops: trim, ltrim, rtrim, strip_tags, strtolower, strtoupper
 */
final class Sanitize implements Rule, MutatingRule
{
    /** @var string[] */
    private array $ops;

    /**
     * @param string[] $ops
     */
    public function __construct(array $ops)
    {
        $this->ops = $ops;
    }

    /**
     * Mutate value in-place before other rules validate it.
     * @param array<string, mixed> $context
     */
    public function mutate(mixed $value, array $context = []): mixed
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            return $value;
        }
        $out = (string)$value;
        foreach ($this->ops as $op) {
            switch ($op) {
                case 'trim':
                    $out = trim($out);
                    break;
                case 'ltrim':
                    $out = ltrim($out);
                    break;
                case 'rtrim':
                    $out = rtrim($out);
                    break;
                case 'strip_tags':
                    $out = strip_tags($out);
                    break;
                case 'strtolower':
                case 'lower':
                    $out = strtolower($out);
                    break;
                case 'strtoupper':
                case 'upper':
                    $out = strtoupper($out);
                    break;
                default:
                    // ignore unknown ops for now
                    break;
            }
        }
        return $out;
    }

    /**
     * Sanitization itself does not produce validation errors.
     * @param array<string, mixed> $context
     */
    public function validate(mixed $value, array $context = []): ?string
    {
        return null;
    }
}
