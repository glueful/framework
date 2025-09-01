<?php

declare(strict_types=1);

namespace Glueful\Extensions\Services\Interfaces;

interface ExtensionConfigInterface
{
    /**
     * Get the global extensions configuration
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array;

    /**
     * Save the global extensions configuration
     *
     * @param array<string, mixed> $config
     */
    public function saveConfig(array $config): bool;

    /**
     * Get configuration for a specific extension
     *
     * @return array<string, mixed>
     */
    public function getExtensionConfig(string $name): array;

    /**
     * Update configuration for a specific extension
     *
     * @param array<string, mixed> $config
     */
    public function updateExtensionConfig(string $name, array $config): void;

    /**
     * Get list of enabled extensions
     *
     * @return string[]
     */
    public function getEnabledExtensions(): array;

    /**
     * Check if an extension is enabled
     */
    public function isEnabled(string $name): bool;

    /**
     * Enable an extension
     */
    public function enableExtension(string $name): void;

    /**
     * Disable an extension
     */
    public function disableExtension(string $name): void;

    /**
     * Get extension settings/options
     *
     * @return array<string, mixed>
     */
    public function getExtensionSettings(string $name): array;

    /**
     * Update extension settings/options
     *
     * @param array<string, mixed> $settings
     */
    public function updateExtensionSettings(string $name, array $settings): void;

    /**
     * Clear configuration cache
     */
    public function clearCache(): void;

    /**
     * Add extension to configuration
     *
     * @param array<string, mixed> $extensionData
     */
    public function addExtension(string $name, array $extensionData): void;

    /**
     * Remove extension from configuration
     */
    public function removeExtension(string $name): void;

    /**
     * Get core extensions
     *
     * @return string[]
     */
    public function getCoreExtensions(): array;

    /**
     * Get optional extensions
     *
     * @return string[]
     */
    public function getOptionalExtensions(): array;

    /**
     * Check if extension is core
     */
    public function isCoreExtension(string $name): bool;

    /**
     * Set debug mode
     */
    public function setDebugMode(bool $enable = true): void;
}
