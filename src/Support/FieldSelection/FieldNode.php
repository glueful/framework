<?php

declare(strict_types=1);

namespace Glueful\Support\FieldSelection;

/**
 * Immutable node representing a selected field and its children.
 * Supports advanced features like aliases, transformations, computed fields, and conditions.
 */
final class FieldNode
{
    /** @param array<string, FieldNode> $children */
    public function __construct(
        public readonly string $name,
        public readonly array $children = [],
        public readonly ?string $alias = null,
        public readonly ?string $transformation = null,
        public readonly bool $isComputed = false,
        public readonly ?string $condition = null
    ) {
    }

    /** @return array<string, FieldNode> */
    public function children(): array
    {
        return $this->children;
    }

    public function has(string $child): bool
    {
        return isset($this->children[$child]);
    }

    public function child(string $child): ?FieldNode
    {
        return $this->children[$child] ?? null;
    }

    /**
     * Get the output field name (alias or original name)
     */
    public function outputName(): string
    {
        return $this->alias ?? $this->name;
    }

    /**
     * Check if this field has an alias
     */
    public function hasAlias(): bool
    {
        return $this->alias !== null;
    }

    /**
     * Check if this field has a transformation
     */
    public function hasTransformation(): bool
    {
        return $this->transformation !== null;
    }

    /**
     * Check if this field has a condition
     */
    public function hasCondition(): bool
    {
        return $this->condition !== null;
    }

    /**
     * Get the transformation function and parameters
     * @return array{function: string, params: array<string>}|null
     */
    public function getTransformation(): ?array
    {
        if ($this->transformation === null) {
            return null;
        }

        // Parse transformation like "format(Y-m-d)" or "currency(USD)"
        if (preg_match('/^(\w+)\(([^)]*)\)$/', $this->transformation, $matches)) {
            $function = $matches[1];
            $paramString = $matches[2];
            $params = $paramString !== '' ? array_map('trim', explode(',', $paramString)) : [];
            return ['function' => $function, 'params' => $params];
        }

        // Simple transformation without parameters
        return ['function' => $this->transformation, 'params' => []];
    }
}
