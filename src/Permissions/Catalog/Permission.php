<?php

declare(strict_types=1);

namespace Glueful\Permissions\Catalog;

/**
 * Declarative permission definition. Fluent builder; the canonical source of a
 * permission's metadata before it is persisted by a provider.
 */
final class Permission
{
    private string $label;
    private ?string $description = null;
    private ?string $category = null;
    private ?string $resourceType = null;
    private ?string $managedBy = null;

    private function __construct(private readonly string $slug)
    {
        $this->label = $slug;
    }

    public static function define(string $slug): self
    {
        return new self($slug);
    }

    public function label(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function category(string $category): self
    {
        $this->category = $category;
        return $this;
    }

    public function resource(string $resourceType): self
    {
        $this->resourceType = $resourceType;
        return $this;
    }

    public function managedBy(string $packageName): self
    {
        $this->managedBy = $packageName;
        return $this;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function getManagedBy(): ?string
    {
        return $this->managedBy;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->label,
            'description' => $this->description,
            'category' => $this->category,
            'resource_type' => $this->resourceType,
            'managed_by' => $this->managedBy,
        ];
    }
}
