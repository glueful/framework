<?php

declare(strict_types=1);

namespace Glueful\Support\Documentation;

/**
 * Produces stable, idiomatic operationId values for OpenAPI operations.
 *
 * Operation IDs become method names on generated SDK clients; quality here
 * propagates to every downstream codegen tool.
 */
final class OperationIdGenerator
{
    /** @var array<string, int> */
    private array $usageCounts = [];

    public function fromRouteName(string $name): string
    {
        $parts = preg_split('/[._\-\/]+/', $name) ?: [];
        return $this->toCamelCase($parts);
    }

    public function fromMethodAndPath(string $method, string $path): string
    {
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));

        $words = [strtolower($method)];
        foreach ($segments as $segment) {
            if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
                $words[] = 'by';
                $words[] = trim($segment, '{}');
            } else {
                $words[] = $segment;
            }
        }
        return $this->toCamelCase($words);
    }

    /**
     * Register an id; returns it (or a numbered variant) to guarantee uniqueness.
     */
    public function register(string $id): string
    {
        $count = ($this->usageCounts[$id] ?? 0) + 1;
        $this->usageCounts[$id] = $count;
        return $count === 1 ? $id : $id . $count;
    }

    /** @param list<string> $words */
    private function toCamelCase(array $words): string
    {
        $sanitized = array_values(array_filter(array_map(
            static fn (string $w): string => preg_replace('/[^a-zA-Z0-9]/', '', $w) ?? '',
            $words,
        ), static fn (string $w): bool => $w !== ''));

        if ($sanitized === []) {
            return 'operation';
        }

        $first = lcfirst($sanitized[0]);
        $rest = array_map(static fn (string $w): string => ucfirst(strtolower($w)), array_slice($sanitized, 1));
        return $first . implode('', $rest);
    }
}
