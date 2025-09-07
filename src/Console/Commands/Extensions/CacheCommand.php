<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ProviderLocator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Enhanced Extensions Cache Command
 *
 * Build extensions cache for production with comprehensive verification:
 * - Cache building functionality
 * - Verification and validation
 * - Performance benchmarking
 */
#[AsCommand(
    name: 'extensions:cache',
    description: 'Build extensions cache for production'
)]
final class CacheCommand extends BaseCommand
{
    private ExtensionManager $extensions;

    public function __construct()
    {
        parent::__construct();
        $this->extensions = $this->getService(ExtensionManager::class);
    }

    protected function configure(): void
    {
        $this->setDescription('Build extensions cache for production with verification');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Building Extensions Cache</info>');
        $output->writeln('=========================');
        $output->writeln('');

        // Performance benchmarking
        $startTime = microtime(true);
        $memoryStart = memory_get_usage(true);

        // Step 1: Validate configuration before building
        $output->writeln('1. <info>Validating configuration...</info>');
        $validationErrors = $this->validateConfiguration();

        if (count($validationErrors) > 0) {
            $output->writeln('<error>Configuration validation failed:</error>');
            foreach ($validationErrors as $error) {
                $output->writeln("   ✗ {$error}");
            }
            return self::FAILURE;
        }
        $output->writeln('   ✓ Configuration is valid');

        // Step 2: Clear existing cache
        $output->writeln('2. <info>Clearing existing cache...</info>');
        $cacheFile = base_path('bootstrap/cache/extensions.php');
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
            $output->writeln('   ✓ Existing cache cleared');
        } else {
            $output->writeln('   - No existing cache found');
        }

        // Step 3: Discover providers
        $output->writeln('3. <info>Discovering providers...</info>');
        $discoveredProviders = ProviderLocator::all();
        $providerCount = count($discoveredProviders);
        $output->writeln("   ✓ Found {$providerCount} providers");

        // Step 4: Validate providers
        $output->writeln('4. <info>Validating providers...</info>');
        $providerErrors = $this->validateProviders($discoveredProviders);

        if (count($providerErrors) > 0) {
            $output->writeln('<error>Provider validation failed:</error>');
            foreach ($providerErrors as $error) {
                $output->writeln("   ✗ {$error}");
            }
            return self::FAILURE;
        }
        $output->writeln("   ✓ All {$providerCount} providers are valid");

        // Step 5: Build cache (in any environment)
        $output->writeln('5. <info>Building cache...</info>');
        try {
            // Write cache deterministically using the validated provider list
            $this->extensions->writeCacheNow($discoveredProviders);
            $output->writeln('   ✓ Cache built successfully');
        } catch (\Throwable $e) {
            $output->writeln("   <error>✗ Cache build failed: {$e->getMessage()}</error>");
            return self::FAILURE;
        }

        // Step 6: Verify cache
        $output->writeln('6. <info>Verifying cache...</info>');
        $cacheVerification = $this->verifyCacheIntegrity();

        if (!$cacheVerification['valid']) {
            $output->writeln("<error>   ✗ Cache verification failed: {$cacheVerification['error']}</error>");
            return self::FAILURE;
        }
        $output->writeln('   ✓ Cache verification passed');

        // Performance metrics
        $endTime = microtime(true);
        $memoryEnd = memory_get_usage(true);
        $buildTime = round(($endTime - $startTime) * 1000, 2);
        $memoryUsed = round(($memoryEnd - $memoryStart) / 1024 / 1024, 2);
        $cacheSize = file_exists($cacheFile) ? round(filesize($cacheFile) / 1024, 2) : 0;

        // Summary
        $output->writeln('');
        $output->writeln('<info>Cache Build Summary:</info>');
        $output->writeln("Providers cached:  {$providerCount}");
        $output->writeln("Cache file size:   {$cacheSize} KB");
        $output->writeln("Build time:        {$buildTime} ms");
        $output->writeln("Memory used:       {$memoryUsed} MB");
        $output->writeln('');
        $output->writeln('<info>Extensions cache ready for production deployment!</info>');

        return self::SUCCESS;
    }

    /**
     * @return array<string>
     */
    private function validateConfiguration(): array
    {
        $errors = [];

        // Check required config values
        $enabled = config('extensions.enabled');
        if ($enabled === null) {
            $errors[] = 'extensions.enabled config is not set';
        }

        // Validate allow-list mode configuration
        $only = config('extensions.only');
        if ($only !== null && !is_array($only) && !is_string($only)) {
            $errors[] = 'extensions.only must be array or string';
        }

        // Validate blacklist configuration
        $disabled = config('extensions.disabled');
        if ($disabled !== null && !is_array($disabled)) {
            $errors[] = 'extensions.disabled must be array';
        }

        // Check for conflicting configurations
        if ($only !== null && $disabled !== null) {
            $errors[] = 'Cannot use both extensions.only (allow-list) and extensions.disabled (blacklist)';
        }

        // Validate local path configuration
        $localPath = config('extensions.local_path');
        if ($localPath !== null && !is_string($localPath)) {
            $errors[] = 'extensions.local_path must be string';
        }

        return $errors;
    }

    /**
     * @param array<class-string> $providers
     * @return array<string>
     */
    private function validateProviders(array $providers): array
    {
        $errors = [];

        foreach ($providers as $providerClass) {
            // Check if class exists
            if (!class_exists($providerClass)) {
                $errors[] = "Provider class not found: {$providerClass}";
                continue;
            }

            // Check if it's a valid ServiceProvider
            if (!is_subclass_of($providerClass, \Glueful\Extensions\ServiceProvider::class)) {
                $errors[] = "Not a valid ServiceProvider: {$providerClass}";
                continue;
            }

            // Check if it can be instantiated
            try {
                $reflection = new \ReflectionClass($providerClass);
                if (!$reflection->isInstantiable()) {
                    $errors[] = "Provider is not instantiable: {$providerClass}";
                }
            } catch (\ReflectionException $e) {
                $errors[] = "Reflection error for {$providerClass}: {$e->getMessage()}";
            }
        }

        return $errors;
    }

    /**
     * @return array{valid: bool, error?: string}
     */
    private function verifyCacheIntegrity(): array
    {
        $cacheFile = base_path('bootstrap/cache/extensions.php');

        // Check if cache file exists
        if (!file_exists($cacheFile)) {
            return ['valid' => false, 'error' => 'Cache file not found'];
        }

        // Check if cache file is readable
        if (!is_readable($cacheFile)) {
            return ['valid' => false, 'error' => 'Cache file is not readable'];
        }

        // Verify cache file structure
        try {
            $cachedProviders = require $cacheFile;

            if (!is_array($cachedProviders)) {
                return ['valid' => false, 'error' => 'Cache file does not return array'];
            }

            // Verify each provider class exists
            foreach ($cachedProviders as $providerClass) {
                if (!is_string($providerClass)) {
                    return ['valid' => false, 'error' => 'Cache contains non-string provider class'];
                }

                if (!class_exists($providerClass)) {
                    return ['valid' => false, 'error' => "Cached provider class not found: {$providerClass}"];
                }
            }
        } catch (\Throwable $e) {
            return ['valid' => false, 'error' => "Cache file parsing error: {$e->getMessage()}"];
        }

        return ['valid' => true];
    }
}
