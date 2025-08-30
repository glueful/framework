<?php

declare(strict_types=1);

namespace Glueful\Configuration;

use Glueful\Configuration\EnvironmentConfigLoader;
use Glueful\Configuration\ConfigValidator;

final class ConfigRepository implements ConfigRepositoryInterface
{
    private array $config = [];
    private bool $loaded = false;
    private readonly bool $cacheEnabled;
    private readonly string $cacheFile;
    private readonly string $environment;

    public function __construct(
        private readonly string $frameworkConfigPath,
        private readonly string $applicationConfigPath,
        ?string $cacheDir = null,
        ?bool $cacheEnabled = null,
        ?string $environment = null
    ) {
        $this->environment = $environment ?? env('APP_ENV', 'production');
        $this->cacheEnabled = $cacheEnabled ?? ($this->environment === 'production');

        $cacheDir = $cacheDir ?? $this->getDefaultCacheDir();
        $this->cacheFile = $cacheDir . '/config.php';

        $this->loadConfigurations();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($key === '' || $key === '.') {
            return $this->config;
        }
        $segments = explode('.', $key);
        $current = $this->config;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }
        return $current;
    }

    public function set(string $key, mixed $value): void
    {
        if ($key === '' || $key === '.') {
            if (is_array($value)) {
                $this->config = $value;
            }
            return;
        }

        $segments = explode('.', $key);
        $current =& $this->config;
        $last = array_pop($segments);
        foreach ($segments as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }
            $current =& $current[$segment];
        }
        $current[$last] = $value;
    }

    public function has(string $key): bool
    {
        return $this->get($key, null) !== null;
    }

    public function all(): array
    {
        return $this->config;
    }

    private function loadConfigurations(): void
    {
        if ($this->loaded) {
            return;
        }

        // Try to load from cache first (production only)
        if ($this->cacheEnabled && $this->hasCachedConfig()) {
            $this->config = $this->loadCachedConfig();
            $this->loaded = true;
            return;
        }

        // Load and merge configurations
        $framework = $this->loadConfigDirectory($this->frameworkConfigPath);
        $application = $this->loadConfigDirectory($this->applicationConfigPath);
        $this->config = $this->deepMerge($framework, $application);

        // Validate merged configuration
        $this->validateConfiguration();

        $this->loaded = true;

        // Save to cache (production only)
        if ($this->cacheEnabled) {
            $this->saveCachedConfig();
        }
    }

    private function loadConfigDirectory(string $path): array
    {
        // Use EnvironmentConfigLoader for environment-aware loading
        $loader = new EnvironmentConfigLoader();
        return $loader->loadEnvironmentConfig($path, $this->environment);
    }

    private function deepMerge(array $base, array $overrides): array
    {
        $merged = $base;
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                // Distinguish assoc vs list
                $isAssoc = static function (array $arr): bool {
                    if ($arr === []) {
                        return false;
                    }
                    return array_keys($arr) !== range(0, count($arr) - 1);
                };

                if ($isAssoc($merged[$key]) || $isAssoc($value)) {
                    $merged[$key] = $this->deepMerge($merged[$key], $value);
                } else {
                    // For lists (e.g., middleware), append and de-duplicate
                    $combined = array_merge($merged[$key], $value);
                    // Only de-duplicate if all values are scalar
                    $allScalar = true;
                    foreach ($combined as $item) {
                        if (!is_scalar($item)) {
                            $allScalar = false;
                            break;
                        }
                    }

                    if ($allScalar) {
                        $merged[$key] = array_values(array_unique($combined));
                    } else {
                        $merged[$key] = $combined;
                    }
                }
            } else {
                $merged[$key] = $value;
            }
        }
        return $merged;
    }

    private function getDefaultCacheDir(): string
    {
        // Try to get from globals first (set by ContainerBootstrap)
        $basePath = $GLOBALS['base_path'] ?? dirname(__DIR__);
        $cacheDir = $basePath . '/storage/cache';

        // Ensure cache directory exists
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        return $cacheDir;
    }

    private function hasCachedConfig(): bool
    {
        if (!file_exists($this->cacheFile)) {
            return false;
        }

        $cacheTime = filemtime($this->cacheFile);
        $configTime = $this->getLatestConfigTime();

        return $cacheTime >= $configTime;
    }

    private function loadCachedConfig(): array
    {
        $cached = require $this->cacheFile;
        return is_array($cached) ? $cached : [];
    }

    private function saveCachedConfig(): void
    {
        try {
            $cacheDir = dirname($this->cacheFile);
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }

            $content = "<?php\n\n// Configuration cache generated on " . date('Y-m-d H:i:s') . "\n";
            $content .= "// Environment: {$this->environment}\n";
            $content .= "// Framework path: {$this->frameworkConfigPath}\n";
            $content .= "// Application path: {$this->applicationConfigPath}\n\n";
            $content .= "return " . var_export($this->config, true) . ";\n";

            file_put_contents($this->cacheFile, $content, LOCK_EX);
        } catch (\Throwable $e) {
            // Don't let cache failures break the application
            error_log("Failed to save config cache: " . $e->getMessage());
        }
    }

    private function getLatestConfigTime(): int
    {
        $times = [];

        // Check framework config times (base + environment)
        $this->addConfigTimes($times, $this->frameworkConfigPath);

        // Check application config times (base + environment)
        $this->addConfigTimes($times, $this->applicationConfigPath);

        return empty($times) ? 0 : max($times);
    }

    /**
     * Add modification times for base and environment-specific config files
     */
    private function addConfigTimes(array &$times, string $configPath): void
    {
        if (!is_dir($configPath)) {
            return;
        }

        // Add base config file times
        foreach (glob(rtrim($configPath, '/') . '/*.php') as $file) {
            $times[] = filemtime($file);
        }

        // Add environment-specific config file times
        $envConfigPath = rtrim($configPath, '/') . '/' . $this->environment;
        if (is_dir($envConfigPath)) {
            foreach (glob($envConfigPath . '/*.php') as $file) {
                $times[] = filemtime($file);
            }
        }
    }

    public function clearCache(): void
    {
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    public function isCacheEnabled(): bool
    {
        return $this->cacheEnabled;
    }

    public function getCacheFile(): string
    {
        return $this->cacheFile;
    }

    /**
     * Validate the current configuration manually
     *
     * @return array List of validation errors (empty if valid)
     */
    public function validateConfig(): array
    {
        $validator = new ConfigValidator();
        return $validator->validate($this->config);
    }

    /**
     * Validate the merged configuration
     *
     * @throws \RuntimeException If configuration validation fails
     */
    private function validateConfiguration(): void
    {
        // Skip validation if disabled via environment variable
        if (($_ENV['SKIP_CONFIG_VALIDATION'] ?? false)) {
            return;
        }

        $validator = new ConfigValidator();
        $violations = $validator->validate($this->config);

        if (!empty($violations)) {
            $message = "Configuration validation failed:\n" . implode("\n", $violations);
            error_log("Configuration validation errors: " . $message);

            // In production, log errors but don't break the application
            // In development, throw exception to catch issues early
            if ($this->environment !== 'production') {
                throw new \RuntimeException($message);
            }
        }
    }
}
