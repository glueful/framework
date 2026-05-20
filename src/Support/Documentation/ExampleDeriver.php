<?php

declare(strict_types=1);

namespace Glueful\Support\Documentation;

/**
 * Builds representative example payloads from Glueful Validator rule strings.
 *
 * Examples populate OpenAPI request/response schemas so generated SDK docs
 * and Swagger UI show realistic values instead of "string" / 0 placeholders.
 */
final class ExampleDeriver
{
    /**
     * @param  array<string, string|list<string>> $rules
     * @return array<string, mixed>
     */
    public function fromValidationRules(array $rules): array
    {
        $example = [];
        foreach ($rules as $field => $rule) {
            $parts = is_array($rule) ? $rule : explode('|', $rule);
            $example[$field] = $this->valueFor($field, $parts);
        }
        return $example;
    }

    /** @param list<string> $rules */
    private function valueFor(string $field, array $rules): mixed
    {
        $type = $this->detectType($rules);
        return match ($type) {
            'integer' => $this->intExample($rules),
            'boolean' => true,
            'email' => 'user@example.com',
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'url' => 'https://example.com',
            'date' => '2026-01-15',
            'datetime' => '2026-01-15T12:00:00+00:00',
            default => $this->stringExample($field),
        };
    }

    /** @param list<string> $rules */
    private function detectType(array $rules): string
    {
        foreach ($rules as $rule) {
            $name = explode(':', $rule, 2)[0];
            if (in_array($name, ['integer', 'boolean', 'email', 'uuid', 'url', 'date', 'datetime'], true)) {
                return $name;
            }
        }
        return 'string';
    }

    /** @param list<string> $rules */
    private function intExample(array $rules): int
    {
        $min = 1;
        $max = 100;
        foreach ($rules as $rule) {
            if (str_starts_with($rule, 'min:')) {
                $min = (int) substr($rule, 4);
            } elseif (str_starts_with($rule, 'max:')) {
                $max = (int) substr($rule, 4);
            }
        }
        return (int) (($min + $max) / 2);
    }

    private function stringExample(string $field): string
    {
        return match (strtolower($field)) {
            'name', 'first_name' => 'Jane',
            'last_name' => 'Doe',
            'title' => 'Example title',
            'slug' => 'example-slug',
            'description' => 'A short description.',
            default => 'example',
        };
    }
}
