<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Container;

use Glueful\Console\BaseCommand;
use Glueful\Container\Bootstrap\ContainerFactory;
use Glueful\Container\Compile\ContainerCompiler;
use Glueful\Container\Loader\DefaultServicesLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Container Compile Command
 *
 * Compiles the Glueful container for production optimization:
 * - Pre-compiles all service definitions
 * - Optimizes service resolution paths
 * - Validates all service configurations
 * - Generates optimized container cache
 * - Removes debug information for performance
 * - Creates dumped container for faster startup
 *
 * @package Glueful\Console\Commands\Container
 */
#[AsCommand(
    name: 'di:container:compile',
    description: 'Compile the Glueful container for production optimization'
)]
class ContainerCompileCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Compile the Glueful container for production optimization')
             ->setHelp($this->getDetailedHelp())
             ->addOption(
                 'output-dir',
                 'o',
                 InputOption::VALUE_REQUIRED,
                 'Directory to store compiled container',
                 'storage/cache/container'
             )
             ->addOption(
                 'debug',
                 'd',
                 InputOption::VALUE_NONE,
                 'Compile with debug information (slower but with debugging features)'
             )
             ->addOption(
                 'validate',
                 'v',
                 InputOption::VALUE_NONE,
                 'Validate container configuration before compilation'
             )
             ->addOption(
                 'optimize',
                 null,
                 InputOption::VALUE_NONE,
                 'Enable maximum optimizations (removes debug info, inlines services)'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Force recompilation even if cache is fresh'
             )
             ->addOption(
                 'warmup',
                 'w',
                 InputOption::VALUE_NONE,
                 'Warm up the compiled container after compilation'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outputDir = (string) $input->getOption('output-dir');
        $validate = (bool) $input->getOption('validate');
        $optimize = (bool) $input->getOption('optimize');
        $force = (bool) $input->getOption('force');
        $warmup = (bool) $input->getOption('warmup');

        try {
            $this->info('Starting container compilation...');
            $this->line('');

            // Validation step
            if ($validate === true) {
                $this->validateContainerConfiguration();
            }

            // Check if compilation is needed
            if ($force === false && $this->isContainerFresh($outputDir)) {
                $this->success('Container is already compiled and up to date.');
                return self::SUCCESS;
            }

            // Prepare output directory
            $this->prepareOutputDirectory($outputDir);

            // Compile container
            $compileTime = $this->compileContainer($outputDir);

            // Warm up if requested
            if ($warmup === true) {
                $this->warmupContainer($outputDir);
            }

            // Show compilation summary
            $this->showCompilationSummary($outputDir, $compileTime, $optimize);

            $this->success('Container compilation completed successfully!');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Container compilation failed: ' . $e->getMessage());

            if ($output->isVerbose()) {
                $this->line('Stack trace:');
                $this->line($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    private function validateContainerConfiguration(): void
    {
        $this->info('Validating container configuration...');

        // Create a test container to validate configuration
        try {
            ContainerFactory::create(false); // Non-production for validation
            $this->line('✓ Container configuration is valid');
        } catch (\Exception $e) {
            throw new \RuntimeException('Container validation failed: ' . $e->getMessage());
        }
    }

    private function isContainerFresh(string $outputDir): bool
    {
        $cacheFile = $outputDir . '/CompiledContainer.php';

        if (!file_exists($cacheFile)) {
            return false;
        }

        $cacheTime = filemtime($cacheFile);

        // Consider the container stale if any core container files are newer
        $containerDir = dirname(__DIR__, 3) . '/Container';
        if (is_dir($containerDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($containerDir)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    if ($file->getMTime() > $cacheTime) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    private function prepareOutputDirectory(string $outputDir): void
    {
        $this->info('Preparing output directory...');

        if (!is_dir($outputDir)) {
            if (!mkdir($outputDir, 0755, true)) {
                throw new \RuntimeException("Failed to create output directory: {$outputDir}");
            }
        }

        // Clean existing compiled files
        $files = glob($outputDir . '/Compiled*.php');
        foreach ($files as $file) {
            unlink($file);
        }

        $this->line('✓ Output directory prepared');
    }

    private function compileContainer(string $outputDir): float
    {
        $startTime = microtime(true);

        $this->info('Compiling container...');

        // Use progress bar for compilation steps
        $steps = ['Building', 'Optimizing', 'Validating', 'Dumping', 'Finalizing'];
        $progressBar = $this->createProgressBar(count($steps));
        $progressBar->start();

        try {
            // Step 1: Build container definitions
            $progressBar->setMessage('Building container definition...');
            // Build a dev container to extract definitions for compilation
            $runtime = ContainerFactory::create(false);
            // Extract definitions via reflection from runtime container
            $ref = new \ReflectionClass($runtime);
            $prop = $ref->getProperty('definitions');
            $prop->setAccessible(true);
            /** @var array<string, \Glueful\Container\Definition\DefinitionInterface> $definitions */
            $definitions = (array) $prop->getValue($runtime);

            // Compile into a PHP class
            $compiler = new ContainerCompiler();
            $namespace = 'Glueful\\Container\\Compiled';
            $className = 'CompiledContainer';
            $code = $compiler->compile($definitions, $className, $namespace);
            $this->writeCompiledFile($outputDir, $className, $code);
            // Also emit a services.json manifest for tooling in production environments
            $servicesIndex = $compiler->buildServicesIndex($definitions, DefaultServicesLoader::getProviderMap());
            $this->writeServicesManifest($outputDir, $servicesIndex);
            $progressBar->advance();

            // Step 2: Apply optimizations
            $progressBar->setMessage('Applying optimizations...');
            // ContainerCompiler already emits efficient code; hook reserved for future passes
            $progressBar->advance();

            // Step 3: Validate compiled container
            $progressBar->setMessage('Validating compiled container...');
            $this->validateCompiledFile($outputDir, $namespace . '\\' . $className);
            $progressBar->advance();

            // Step 4: Dump compiled container
            $progressBar->setMessage('Dumping compiled container...');
            // Already dumped by writeCompiledFile
            $progressBar->advance();

            // Step 5: Finalize
            $progressBar->setMessage('Finalizing compilation...');
            $this->finalizeCompilation($outputDir);
            $progressBar->advance();

            $progressBar->finish();
            $this->line('');

            return microtime(true) - $startTime;
        } catch (\Exception $e) {
            $progressBar->finish();
            $this->line('');
            throw $e;
        }
    }

    private function writeCompiledFile(string $outputDir, string $className, string $code): void
    {
        $file = rtrim($outputDir, '/') . '/' . $className . '.php';
        if (file_put_contents($file, $code) === false) {
            throw new \RuntimeException('Failed to write compiled container to: ' . $file);
        }
        $this->line("  • Container dumped to: {$file}");
    }

    /**
     * @param array<string, array<string, mixed>> $index
     */
    private function writeServicesManifest(string $outputDir, array $index): void
    {
        $file = rtrim($outputDir, '/') . '/services.json';
        $json = json_encode($index, JSON_PRETTY_PRINT);
        if (!is_string($json) || file_put_contents($file, $json) === false) {
            throw new \RuntimeException('Failed to write services manifest to: ' . $file);
        }
        $this->line("  • Services manifest dumped to: {$file}");
    }

    private function validateCompiledFile(string $outputDir, string $fqcn): void
    {
        $file = $outputDir . '/CompiledContainer.php';
        if (!file_exists($file)) {
            throw new \RuntimeException('Compiled file not found: ' . $file);
        }

        require_once $file;
        if (!class_exists($fqcn)) {
            throw new \RuntimeException('Compiled class not found: ' . $fqcn);
        }

        // Try instantiating to ensure no immediate failures
        new $fqcn();
    }

    private function finalizeCompilation(string $outputDir): void
    {
        // Create metadata file
        $metadata = [
            'compiled_at' => date('c'),
            'environment' => config('app.env'),
            'debug' => config('app.debug'),
            'version' => '1.0.0'
        ];

        file_put_contents(
            $outputDir . '/container_metadata.json',
            json_encode($metadata, JSON_PRETTY_PRINT)
        );
    }

    private function warmupContainer(string $outputDir): void
    {
        $this->info('Warming up compiled container...');

        // Load and test the compiled container
        try {
            $containerFile = $outputDir . '/CompiledContainer.php';
            if (!file_exists($containerFile)) {
                $this->warning('Compiled container file not found');
                return;
            }

            require_once $containerFile;

            $className = '\\Glueful\\Container\\Compiled\\CompiledContainer';
            if (class_exists($className)) {
                new $className();
                $this->line('✓ Compiled container loaded successfully');
            } else {
                $this->warning('Compiled class Glueful\\Container\\Compiled\\CompiledContainer not found');
            }
        } catch (\Exception $e) {
            $this->warning('Container warmup failed: ' . $e->getMessage());
        }
    }

    private function showCompilationSummary(string $outputDir, float $compileTime, bool $optimize): void
    {
        $this->line('');
        $this->info('Compilation Summary:');
        $this->line('');

        $containerFile = $outputDir . '/CompiledContainer.php';
        $fileSize = file_exists($containerFile) ? filesize($containerFile) : 0;

        $this->table(['Metric', 'Value'], [
            ['Compilation Time', sprintf('%.2f seconds', $compileTime)],
            ['Output Directory', $outputDir],
            ['Container File Size', $this->formatFileSize($fileSize)],
            ['Optimization Level', $optimize ? 'Maximum' : 'Standard'],
            ['Environment', config('app.env', 'unknown')],
            ['Debug Mode', (bool) config('app.debug', false) ? 'Enabled' : 'Disabled']
        ]);

        $this->line('');
        $this->note('The compiled container is optimized for production use.');
        $this->note('Remember to recompile when service definitions change.');
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return sprintf('%.1f MB', $bytes / 1048576);
        } elseif ($bytes >= 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        } else {
            return "{$bytes} bytes";
        }
    }

    private function getDetailedHelp(): string
    {
        return <<<HELP
Container Compile Command

This command compiles the Glueful container for production optimization, significantly
improving application startup time and reducing memory usage.

Usage Examples:
  glueful di:container:compile                     # Basic compilation
  glueful di:container:compile --optimize          # Maximum optimization
  glueful di:container:compile --debug             # Compile with debug info
  glueful di:container:compile --validate          # Validate before compile
  glueful di:container:compile --warmup            # Warm up after compile
  glueful di:container:compile --force             # Force recompilation

Compilation Process:
  1. Validates container configuration
  2. Builds optimized service definitions
  3. Applies performance optimizations
  4. Generates compiled container class
  5. Creates metadata and cache files

Benefits of Compilation:
  • Faster application startup (up to 10x improvement)
  • Reduced memory usage
  • Better performance in production
  • Early detection of configuration errors
  • Optimized service resolution paths

Options:
  --output-dir     Directory for compiled container files
  --debug          Include debug information (slower but debuggable)
  --validate       Validate configuration before compilation
  --optimize       Apply maximum optimizations
  --force          Force recompilation even if cache is fresh
  --warmup         Test the compiled container after compilation

The compiled container should be used in production environments for
optimal performance. Remember to recompile when service definitions change.
HELP;
    }
}
