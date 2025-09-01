<?php

declare(strict_types=1);

namespace Glueful\Extensions\Services\Interfaces;

interface ExtensionCatalogInterface
{
    /**
     * Get available extensions from the marketplace/registry
     *
     * @return array<string, mixed>
     */
    public function getAvailableExtensions(): array;

    /**
     * Search extensions in the catalog
     *
     * @return array<int, mixed>
     */
    public function searchExtensions(string $query): array;

    /**
     * Download an extension from remote source
     */
    public function downloadExtension(string $name, ?string $version = null): string;

    /**
     * Get metadata for a remote extension
     *
     * @return array<string, mixed>
     */
    public function getRemoteMetadata(string $name): array;

    /**
     * Check for available updates for installed extensions
     *
     * @return array<string, mixed>
     */
    public function checkForUpdates(): array;

    /**
     * Get extension categories from the catalog
     *
     * @return string[]
     */
    public function getCategories(): array;

    /**
     * Get popular/featured extensions
     *
     * @return array<string, mixed>
     */
    public function getFeaturedExtensions(): array;

    /**
     * Get extensions by category
     *
     * @return array<string, mixed>
     */
    public function getExtensionsByCategory(string $category): array;

    /**
     * Verify extension package integrity
     */
    public function verifyPackage(string $packagePath): bool;

    /**
     * Extract downloaded extension package
     */
    public function extractPackage(string $packagePath, string $destination): bool;

    /**
     * Clear catalog cache
     */
    public function clearCache(): bool;

    /**
     * Set debug mode
     */
    public function setDebugMode(bool $enable = true): void;

    /**
     * Get registry URL
     */
    public function getRegistryUrl(): string;
}
