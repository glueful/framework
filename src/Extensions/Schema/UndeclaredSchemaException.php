<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

/**
 * Thrown by the schema-on-enable operations (executor, readiness, normalization, adoption) when a
 * Glueful package has no manifest migration declaration. Such packages stay bootable; they simply
 * cannot participate in migrate-before-enable until they declare descriptors or "migrations": "none".
 */
final class UndeclaredSchemaException extends \RuntimeException
{
    public static function for(string $package): self
    {
        return new self(
            "Package {$package} declares no extra.glueful.migrations manifest; add descriptors or "
            . '"migrations": "none" before it can participate in schema-on-enable operations.'
        );
    }

    /** The strict-host refusal: an undeclared package may not register migration paths at all. */
    public static function requiredByHost(string $package, string $provider): self
    {
        return new self(
            "{$provider} registers migrations for package {$package}, which declares no "
            . 'extra.glueful.migrations manifest — this host requires declared packages '
            . '(extensions.schema.require_declared_packages). Declare descriptors or '
            . '"migrations": "none".'
        );
    }
}
