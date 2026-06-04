<?php

declare(strict_types=1);

namespace Glueful\Permissions\Catalog;

/**
 * Declarative role definition. A role names a set of granted permission slugs;
 * it does NOT assign users to the role (that is a provider/runtime concern).
 */
final class Role
{
    private string $label;
    private ?string $description = null;
    /** @var string[] */
    private array $grants = [];
    private int $level = 0;
    private ?string $parent = null;
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

    /** @param string[] $permissionSlugs */
    public function grants(array $permissionSlugs): self
    {
        $this->grants = array_values($permissionSlugs);
        return $this;
    }

    public function level(int $level): self
    {
        $this->level = $level;
        return $this;
    }

    public function parent(string $roleSlug): self
    {
        $this->parent = $roleSlug;
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

    /** @return string[] */
    public function grantedPermissions(): array
    {
        return $this->grants;
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
            'grants' => $this->grants,
            'level' => $this->level,
            'parent' => $this->parent,
            'managed_by' => $this->managedBy,
        ];
    }
}
