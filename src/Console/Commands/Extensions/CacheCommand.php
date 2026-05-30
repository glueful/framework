<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\ExtensionManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Extensions Cache Command
 *
 * Compiles the extension provider manifest to bootstrap/cache/extensions.php.
 * Strict: resolves the enabled allow-list through the shared resolver and refuses
 * to write the cache if resolution reports any error (missing provider/dependency,
 * version mismatch, cycle). Production boots from this compiled manifest.
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

        $startTime = microtime(true);
        $memoryStart = memory_get_usage(true);

        // Step 1: Validate configuration.
        $output->writeln('1. <info>Validating configuration...</info>');
        $enabled = config($this->getContext(), 'extensions.enabled');
        if ($enabled !== null && !is_array($enabled)) {
            $output->writeln('<error>   ✗ extensions.enabled must be an array.</error>');
            return self::FAILURE;
        }
        $output->writeln('   ✓ Configuration is valid');

        // Step 2: Resolve providers (strict — fail on any resolver error).
        $output->writeln('2. <info>Resolving providers...</info>');
        $classes = $this->extensions->resolveProviderClasses();
        $errors = $this->extensions->getResolverErrors();
        if ($errors !== []) {
            foreach ($errors as $e) {
                $output->writeln("   <error>✗ [{$e->kind}] {$e->message}</error>");
            }
            $output->writeln('<error>Refusing to write extension cache with unresolved errors.</error>');
            return self::FAILURE;
        }
        $providerCount = count($classes);
        $output->writeln("   ✓ Resolved {$providerCount} providers");

        // Step 3: Clear existing cache.
        $output->writeln('3. <info>Clearing existing cache...</info>');
        $cacheFile = base_path($this->getContext(), 'bootstrap/cache/extensions.php');
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
            $output->writeln('   ✓ Existing cache cleared');
        } else {
            $output->writeln('   - No existing cache found');
        }

        // Step 4: Build cache from the resolved list.
        $output->writeln('4. <info>Building cache...</info>');
        try {
            $this->extensions->writeCacheNow($classes);
            $output->writeln('   ✓ Cache built successfully');
        } catch (\Throwable $e) {
            $output->writeln("   <error>✗ Cache build failed: {$e->getMessage()}</error>");
            return self::FAILURE;
        }

        // Step 5: Verify cache integrity.
        $output->writeln('5. <info>Verifying cache...</info>');
        $verification = $this->verifyCacheIntegrity();
        if (!$verification['valid']) {
            $output->writeln("<error>   ✗ Cache verification failed: {$verification['error']}</error>");
            return self::FAILURE;
        }
        $output->writeln('   ✓ Cache verification passed');

        // Performance metrics.
        $buildTime = round((microtime(true) - $startTime) * 1000, 2);
        $memoryUsed = round((memory_get_usage(true) - $memoryStart) / 1024 / 1024, 2);
        $cacheSize = file_exists($cacheFile) ? round((int) filesize($cacheFile) / 1024, 2) : 0;

        $output->writeln('');
        $output->writeln('<info>Cache Build Summary:</info>');
        $output->writeln("Providers resolved: {$providerCount}");
        $output->writeln("Cache file size:    {$cacheSize} KB");
        $output->writeln("Build time:         {$buildTime} ms");
        $output->writeln("Memory used:        {$memoryUsed} MB");
        $output->writeln('');
        $output->writeln('<info>Extensions cache ready for production deployment!</info>');

        return self::SUCCESS;
    }

    /**
     * @return array{valid: bool, error?: string}
     */
    private function verifyCacheIntegrity(): array
    {
        $cacheFile = base_path($this->getContext(), 'bootstrap/cache/extensions.php');

        if (!file_exists($cacheFile)) {
            return ['valid' => false, 'error' => 'Cache file not found'];
        }
        if (!is_readable($cacheFile)) {
            return ['valid' => false, 'error' => 'Cache file is not readable'];
        }

        try {
            $cachedProviders = require $cacheFile;
            if (!is_array($cachedProviders)) {
                return ['valid' => false, 'error' => 'Cache file does not return array'];
            }
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
