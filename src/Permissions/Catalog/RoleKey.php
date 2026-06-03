<?php

declare(strict_types=1);

namespace Glueful\Permissions\Catalog;

/**
 * Canonical role-reference key shared by enforcement (GateAttributeMiddleware) and the
 * permissions:diff scanner, so the two never diverge.
 *
 * Contract: a dotted value is already canonical and passes through unchanged; a bare value
 * is prefixed with "role." — e.g. 'admin' => 'role.admin', 'role.admin' => 'role.admin',
 * 'blog.editor' => 'blog.editor'.
 */
final class RoleKey
{
    public static function canonical(string $role): string
    {
        return str_contains($role, '.') ? $role : "role.{$role}";
    }
}
