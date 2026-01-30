<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Enhanced Extensions Clear Command
 *
 * Clear extensions cache with development reset functionality:
 * - Cache invalidation
 * - Development reset functionality
 * - Comprehensive cleanup options
 */
#[AsCommand(
    name: 'extensions:clear',
    description: 'Clear extensions cache'
)]
final class ClearCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setDescription('Clear extensions cache with optional development reset')
            ->addOption(
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Clear all extension-related caches and temporary files'
            )
            ->addOption(
                'reset',
                'r',
                InputOption::VALUE_NONE,
                'Full development reset (clear cache, reset discovery, invalidate OPcache)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $clearAll = (bool) $input->getOption('all');
        $fullReset = (bool) $input->getOption('reset');

        $output->writeln('<info>Clearing Extensions Cache</info>');
        $output->writeln('=========================');
        $output->writeln('');

        $clearedItems = 0;

        // Step 1: Clear main extensions cache
        $output->writeln('1. <info>Clearing main extensions cache...</info>');
        $mainCache = base_path($this->getContext(), 'bootstrap/cache/extensions.php');

        if (file_exists($mainCache)) {
            if (@unlink($mainCache)) {
                $output->writeln('   ✓ Main cache file cleared');
                $clearedItems++;

                // Invalidate OPcache for the deleted file
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($mainCache, true);
                    $output->writeln('   ✓ OPcache invalidated');
                }
            } else {
                $output->writeln('   <error>✗ Failed to clear main cache file</error>');
            }
        } else {
            $output->writeln('   - No main cache file found');
        }

        // Step 2: Clear additional caches if --all flag is used
        if ($clearAll || $fullReset) {
            $output->writeln('2. <info>Clearing additional extension caches...</info>');

            // Clear any versioned cache files
            $cachePattern = base_path($this->getContext(), 'bootstrap/cache/extensions*.php');
            $cacheFiles = glob($cachePattern);
            if ($cacheFiles !== false) {
                foreach ($cacheFiles as $file) {
                    if (is_file($file) && @unlink($file)) {
                        $filename = basename($file);
                        $output->writeln("   ✓ Cleared {$filename}");
                        $clearedItems++;

                        if (function_exists('opcache_invalidate')) {
                            @opcache_invalidate($file, true);
                        }
                    }
                }
            }

            // Clear temporary extension files
            $tempPattern = base_path($this->getContext(), 'storage/framework/cache/extensions*');
            $tempFiles = glob($tempPattern);
            if ($tempFiles !== false) {
                foreach ($tempFiles as $file) {
                    if (is_file($file) && @unlink($file)) {
                        $filename = basename($file);
                        $output->writeln("   ✓ Cleared temp file {$filename}");
                        $clearedItems++;
                    }
                }
            }
        }

        // Step 3: Full development reset
        if ($fullReset) {
            $output->writeln('3. <info>Performing full development reset...</info>');

            // Clear Composer autoload cache if in development
            $appEnv = $_ENV['APP_ENV'] ?? (getenv('APP_ENV') !== false ? getenv('APP_ENV') : 'production');
            if ($appEnv !== 'production') {
                $composerCache = base_path($this->getContext(), 'vendor/composer');
                if (is_dir($composerCache)) {
                    // Clear Composer's installed.php cache
                    $installedPhp = $composerCache . '/installed.php';
                    if (file_exists($installedPhp)) {
                        touch($installedPhp); // Update timestamp to force reload
                        $output->writeln('   ✓ Composer installed.php cache refreshed');
                    }
                }

                // Clear local extension scan cache (if any)
                $localPath = config($this->getContext(), 'extensions.local_path');
                if ($localPath !== null && is_string($localPath)) {
                    $cacheFileName = "storage/framework/cache/local_extensions_{$localPath}_scan.php";
                    $scanCacheFile = base_path($this->getContext(), $cacheFileName);
                    if (file_exists($scanCacheFile) && @unlink($scanCacheFile)) {
                        $output->writeln('   ✓ Local extension scan cache cleared');
                        $clearedItems++;
                    }
                }

                // Reset any runtime caches
                if (function_exists('opcache_reset')) {
                    @opcache_reset();
                    $output->writeln('   ✓ OPcache completely reset');
                } elseif (function_exists('apc_clear_cache')) {
                    @apc_clear_cache();
                    $output->writeln('   ✓ APC cache cleared');
                }
            }

            // Clear config cache that might affect extensions
            $configCache = base_path($this->getContext(), 'bootstrap/cache/config.php');
            if (file_exists($configCache)) {
                @unlink($configCache);
                $output->writeln('   ✓ Configuration cache cleared');
                $clearedItems++;
            }
        }

        // Summary
        $output->writeln('');
        if ($clearedItems > 0) {
            $output->writeln("<info>✓ Cache clearing completed! Cleared {$clearedItems} items.</info>");

            if ($fullReset) {
                $output->writeln('');
                $output->writeln('<comment>Development environment has been reset.</comment>');
                $output->writeln('<comment>Extension discovery will be performed fresh on next request.</comment>');
            }
        } else {
            $output->writeln('<comment>No cache files found to clear.</comment>');
        }

        return self::SUCCESS;
    }
}
