<?php

declare(strict_types=1);

namespace Glueful\Extensions\Services;

use Composer\Autoload\ClassLoader;
use Glueful\Extensions\Exceptions\ExtensionException;
use Psr\Log\LoggerInterface;

/**
 * Composer Extension Discovery Service
 *
 * Discovers and validates Composer packages of type "glueful-extension".
 * Provides integration between Composer packages and the Glueful extension system.
 *
 * **Supported Package Formats:**
 * - `type: "glueful-extension"` in composer.json
 * - `extra.glueful.extension-class` for main extension class
 * - PSR-4 autoloading configuration
 * - Optional extension dependencies and configuration
 */
class ComposerExtensionDiscovery
{
    private ?array $discoveredPackages = null;
    private bool $debug = false;

    public function __construct(
        private ?string $projectRoot = null,
        private ?LoggerInterface $logger = null
    ) {
        $this->projectRoot = $projectRoot ?? $this->detectProjectRoot();
    }

    /**
     * Set debug mode for detailed logging
     */
    public function setDebugMode(bool $enabled = true): void
    {
        $this->debug = $enabled;
    }

    /**
     * Discover all Composer packages of type "glueful-extension"
     *
     * @return array Array of extension packages with metadata
     */
    public function discoverExtensionPackages(): array
    {
        if ($this->discoveredPackages !== null) {
            return $this->discoveredPackages;
        }

        $this->discoveredPackages = [];
        $installedJsonPath = $this->projectRoot . '/vendor/composer/installed.json';

        if (!file_exists($installedJsonPath)) {
            $this->debugLog("No vendor/composer/installed.json found, skipping Composer extension discovery");
            return $this->discoveredPackages;
        }

        try {
            $installed = json_decode(file_get_contents($installedJsonPath), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ExtensionException("Invalid JSON in installed.json: " . json_last_error_msg());
            }

            // Handle both composer v1 and v2 formats
            $packages = $installed['packages'] ?? $installed;

            foreach ($packages as $package) {
                if ($this->isGluefulExtension($package)) {
                    $extensionData = $this->parseExtensionPackage($package);
                    if ($extensionData) {
                        $this->discoveredPackages[] = $extensionData;
                        $this->debugLog("Discovered Composer extension: {$package['name']}");
                    }
                }
            }

            $this->debugLog("Discovered " . count($this->discoveredPackages) . " Composer extensions");
        } catch (\Exception $e) {
            $this->debugLog("Error discovering Composer extensions: " . $e->getMessage());
            throw new ExtensionException("Failed to discover Composer extensions: " . $e->getMessage());
        }

        return $this->discoveredPackages;
    }

    /**
     * Get extension package by name
     *
     * @param string $packageName Composer package name (e.g., 'glueful/rbac-extension')
     * @return array|null Extension package data or null if not found
     */
    public function getExtensionPackage(string $packageName): ?array
    {
        $packages = $this->discoverExtensionPackages();

        foreach ($packages as $package) {
            if ($package['package_name'] === $packageName) {
                return $package;
            }
        }

        return null;
    }

    /**
     * Get extension package by extension name
     *
     * @param string $extensionName Extension name (e.g., 'RBAC')
     * @return array|null Extension package data or null if not found
     */
    public function getExtensionPackageByName(string $extensionName): ?array
    {
        $packages = $this->discoverExtensionPackages();

        foreach ($packages as $package) {
            if ($package['extension_name'] === $extensionName) {
                return $package;
            }
        }

        return null;
    }

    /**
     * Check if a package is a Glueful extension
     *
     * @param array $package Composer package data
     * @return bool True if package is a Glueful extension
     */
    private function isGluefulExtension(array $package): bool
    {
        // Check for type "glueful-extension"
        if (($package['type'] ?? '') !== 'glueful-extension') {
            return false;
        }

        // Check for required extra.glueful metadata
        if (!isset($package['extra']['glueful']['extension-class'])) {
            $this->debugLog("Package {$package['name']} lacks required extra.glueful.extension-class");
            return false;
        }

        return true;
    }

    /**
     * Parse extension package data and validate structure
     *
     * @param array $package Composer package data
     * @return array|null Parsed extension data or null if invalid
     */
    private function parseExtensionPackage(array $package): ?array
    {
        try {
            $gluefulConfig = $package['extra']['glueful'] ?? [];
            $extensionClass = $gluefulConfig['extension-class'] ?? null;

            if (!$extensionClass) {
                $this->debugLog("Package {$package['name']} missing extension-class");
                return null;
            }

            // Extract extension name from class name
            $extensionName = $this->extractExtensionName($extensionClass);

            // Get package installation path
            $installPath = $this->getPackageInstallPath($package);

            if (!$installPath || !is_dir($installPath)) {
                $this->debugLog("Package {$package['name']} install path not found: {$installPath}");
                return null;
            }

            // Validate extension class exists
            if (!$this->validateExtensionClass($extensionClass, $package)) {
                $this->debugLog("Extension class {$extensionClass} validation failed");
                return null;
            }

            return [
                'package_name' => $package['name'],
                'extension_name' => $extensionName,
                'extension_class' => $extensionClass,
                'version' => $package['version'] ?? '1.0.0',
                'description' => $package['description'] ?? '',
                'authors' => $package['authors'] ?? [],
                'license' => $package['license'] ?? 'MIT',
                'install_path' => $installPath,
                'autoload' => $package['autoload'] ?? [],
                'dependencies' => $gluefulConfig['extension-dependencies'] ?? [],
                'config_file' => $gluefulConfig['extension-config'] ?? null,
                'glueful_metadata' => $gluefulConfig,
                'source_type' => 'composer'
            ];
        } catch (\Exception $e) {
            $this->debugLog("Failed to parse extension package {$package['name']}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract extension name from class name
     *
     * @param string $className Full extension class name
     * @return string Extension name
     */
    private function extractExtensionName(string $className): string
    {
        // Extract from namespaced class name
        // E.g., "Glueful\Extensions\RBAC\RBACExtension" -> "RBAC"
        $parts = explode('\\', $className);
        $classShortName = end($parts);

        // Remove "Extension" suffix if present
        if (str_ends_with($classShortName, 'Extension')) {
            $nameWithoutSuffix = substr($classShortName, 0, -9);

            // If removing Extension leaves something meaningful, use it
            if (!empty($nameWithoutSuffix)) {
                return $nameWithoutSuffix;
            }
        }

        // Try to extract from namespace path for Glueful extensions
        if (count($parts) >= 3 && in_array('Extensions', $parts)) {
            $extensionsIndex = array_search('Extensions', $parts);
            if ($extensionsIndex !== false && isset($parts[$extensionsIndex + 1])) {
                return $parts[$extensionsIndex + 1];
            }
        }

        // Fallback: return full class name without Extension suffix
        return str_ends_with($classShortName, 'Extension')
            ? substr($classShortName, 0, -9)
            : $classShortName;
    }

    /**
     * Get package installation path
     *
     * @param array $package Composer package data
     * @return string|null Installation path or null if not found
     */
    private function getPackageInstallPath(array $package): ?string
    {
        $packageName = $package['name'];

        // Standard Composer vendor directory path
        $vendorPath = $this->projectRoot . '/vendor/' . $packageName;

        if (is_dir($vendorPath)) {
            return $vendorPath;
        }

        // Try alternative path (for packages with custom installer)
        if (isset($package['extra']['installer-name'])) {
            $customPath = $this->projectRoot . '/vendor/' . $package['extra']['installer-name'];
            if (is_dir($customPath)) {
                return $customPath;
            }
        }

        return null;
    }

    /**
     * Validate that extension class exists and is accessible
     *
     * @param string $className Extension class name
     * @param array $package Package data for autoloading
     * @return bool True if class is valid
     */
    private function validateExtensionClass(string $className, array $package): bool
    {
        // Check if class already exists (autoloader may have loaded it)
        if (class_exists($className)) {
            return true;
        }

        // Try to determine if class file exists based on PSR-4 mapping
        $autoload = $package['autoload']['psr-4'] ?? [];
        $installPath = $this->getPackageInstallPath($package);

        if (!$installPath || empty($autoload)) {
            return false;
        }

        foreach ($autoload as $namespace => $path) {
            if (str_starts_with($className . '\\', $namespace)) {
                $relativeClass = substr($className, strlen($namespace) - 1);
                $classFile = $installPath . '/' . trim($path, '/') . '/' . str_replace('\\', '/', $relativeClass) . '.php';

                if (file_exists($classFile)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Register PSR-4 autoloading for discovered extensions
     *
     * @param ClassLoader|null $classLoader Composer autoloader instance
     * @return void
     */
    public function registerAutoloading(?ClassLoader $classLoader = null): void
    {
        if (!$classLoader) {
            $classLoader = $this->getComposerAutoloader();
        }

        if (!$classLoader) {
            $this->debugLog("No Composer autoloader available for extension PSR-4 registration");
            return;
        }

        $packages = $this->discoverExtensionPackages();

        foreach ($packages as $package) {
            $this->registerPackageAutoloading($package, $classLoader);
        }
    }

    /**
     * Register PSR-4 autoloading for a specific package
     *
     * @param array $package Extension package data
     * @param ClassLoader $classLoader Composer autoloader
     */
    private function registerPackageAutoloading(array $package, ClassLoader $classLoader): void
    {
        $autoload = $package['autoload']['psr-4'] ?? [];
        $installPath = $package['install_path'];

        foreach ($autoload as $namespace => $path) {
            $fullPath = $installPath . '/' . trim($path, '/');

            if (is_dir($fullPath)) {
                $classLoader->setPsr4($namespace, $fullPath);
                $this->debugLog("Registered PSR-4 namespace {$namespace} -> {$fullPath}");
            }
        }
    }

    /**
     * Get Composer autoloader instance
     *
     * @return ClassLoader|null Autoloader instance or null if not found
     */
    private function getComposerAutoloader(): ?ClassLoader
    {
        $vendorDir = $this->projectRoot . '/vendor';
        $autoloadFile = $vendorDir . '/autoload.php';

        if (!file_exists($autoloadFile)) {
            return null;
        }

        try {
            $autoloader = require $autoloadFile;
            return $autoloader instanceof ClassLoader ? $autoloader : null;
        } catch (\Exception $e) {
            $this->debugLog("Failed to load Composer autoloader: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Detect project root directory
     *
     * @return string Project root path
     */
    private function detectProjectRoot(): string
    {
        // Try to get from globals (set by framework bootstrap)
        if (isset($GLOBALS['base_path'])) {
            return $GLOBALS['base_path'];
        }

        // Search upward for composer.json
        $currentDir = __DIR__;
        for ($i = 0; $i < 10; $i++) { // Limit search depth
            if (file_exists($currentDir . '/composer.json')) {
                return $currentDir;
            }
            $parentDir = dirname($currentDir);
            if ($parentDir === $currentDir) {
                break; // Reached filesystem root
            }
            $currentDir = $parentDir;
        }

        // Fallback to current working directory
        return getcwd() ?: __DIR__;
    }

    /**
     * Clear discovery cache
     */
    public function clearCache(): void
    {
        $this->discoveredPackages = null;
        $this->debugLog("Composer extension discovery cache cleared");
    }

    /**
     * Debug logging helper
     */
    private function debugLog(string $message): void
    {
        if (!$this->debug) {
            return;
        }

        if ($this->logger) {
            $this->logger->debug("[ComposerExtensionDiscovery] {$message}");
        } else {
            error_log("[ComposerExtensionDiscovery] {$message}");
        }
    }
}
