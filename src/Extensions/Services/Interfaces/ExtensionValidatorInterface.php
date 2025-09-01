<?php

declare(strict_types=1);

namespace Glueful\Extensions\Services\Interfaces;

interface ExtensionValidatorInterface
{
    /**
     * Validate an extension completely
     *
     * @return array<string, mixed>
     */
    public function validateExtension(string $path): array;

    /**
     * Validate extension dependencies
     *
     * @param array<string, string> $dependencies
     */
    public function validateDependencies(array $dependencies): bool;

    /**
     * Perform basic security validation
     *
     * @return string[]
     */
    public function validateSecurity(string $path): array;

    /**
     * Check framework compatibility
     *
     * @param array<string, mixed> $metadata
     */
    public function checkCompatibility(array $metadata): bool;

    /**
     * Validate manifest.json structure
     *
     * @param array<string, mixed> $manifest
     * @return string[]
     */
    public function validateManifest(array $manifest): array;

    /**
     * Check if extension files exist and are readable
     *
     * @param array<string, mixed> $manifest
     */
    public function validateFiles(string $path, array $manifest): bool;

    /**
     * Validate extension PHP syntax
     *
     * @return string[]
     */
    public function validateSyntax(string $path): array;

    /**
     * Check for naming conflicts
     */
    public function checkNameConflicts(string $name): bool;

    /**
     * Validate extension permissions
     */
    public function validatePermissions(string $path): bool;

    /**
     * Set debug mode
     */
    public function setDebugMode(bool $enable = true): void;
}
