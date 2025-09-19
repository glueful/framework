<?php

declare(strict_types=1);

namespace Glueful\Validation;

use Glueful\Validation\Contracts\Rule;
use Glueful\Validation\Contracts\MutatingRule;
use Glueful\Validation\Contracts\ValidatorInterface;

final class Validator implements ValidatorInterface
{
    /** @var array<string, Rule[]> */
    private array $rules;
    /** @var array<string, mixed> */
    private array $filtered = [];

    /** @param array<string, Rule[]> $rules */
    public function __construct(array $rules = [])
    {
        $this->rules = $rules;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function validate(array $data): array
    {
        $errors = [];
        foreach ($this->rules as $field => $rules) {
            $value = $data[$field] ?? null;
            foreach ($rules as $rule) {
                if ($rule instanceof MutatingRule) {
                    $value = $rule->mutate($value, ['field' => $field, 'data' => $data]);
                    continue;
                }
                $msg = $rule->validate($value, ['field' => $field, 'data' => $data]);
                if ($msg !== null) {
                    $errors[$field][] = $msg;
                }
            }
            $this->filtered[$field] = $value;
        }
        return $errors;
    }

    /**
     * Return the last filtered/sanitized data seen during validate().
     * @return array<string, mixed>
     */
    public function filtered(): array
    {
        return $this->filtered;
    }
}
