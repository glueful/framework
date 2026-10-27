<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Scaffold;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scaffold Job Command
 *
 * Generates a new queue job class extending the base Job class.
 *
 * @package Glueful\Console\Commands\Scaffold
 */
#[AsCommand(
    name: 'scaffold:job',
    description: 'Scaffold a new queue job class'
)]
class JobCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setDescription('Scaffold a new queue job class')
            ->setHelp($this->getDetailedHelp())
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The name of the job class (e.g., ProcessPayment)'
            )
            ->addOption(
                'queue',
                null,
                InputOption::VALUE_OPTIONAL,
                'The queue to push this job to (used in the dispatch example)',
                'default'
            )
            ->addOption(
                'tries',
                null,
                InputOption::VALUE_OPTIONAL,
                'Number of retry attempts',
                '3'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Overwrite existing file if it exists'
            )
            ->addOption(
                'path',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Custom path for the job file',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $name */
        $name = $input->getArgument('name');
        /** @var bool $force */
        $force = (bool) $input->getOption('force');
        /** @var string|null $customPath */
        $customPath = $input->getOption('path');
        $customPath = is_string($customPath) ? $customPath : null;

        // Get job options
        /** @var string $queue */
        $queue = $input->getOption('queue') ?? 'default';
        /** @var string $tries */
        $tries = $input->getOption('tries') ?? '3';

        // Normalize the name
        $name = $this->normalizeJobName($name);

        // Validate the name
        if (!$this->isValidClassName($name)) {
            $this->error("Invalid job name: {$name}");
            $this->line('Class names must be PascalCase and contain only letters and numbers.');
            return self::FAILURE;
        }

        // Determine the file path
        $basePath = $customPath ?? $this->getDefaultJobPath();
        $filePath = $this->buildFilePath($basePath, $name);

        // Check if file exists
        if (file_exists($filePath) && !$force) {
            $this->error("Job already exists: {$filePath}");
            $this->line('Use --force to overwrite.');
            return self::FAILURE;
        }

        // Create directory if it doesn't exist
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                $this->error("Failed to create directory: {$directory}");
                return self::FAILURE;
            }
        }

        // Generate the class content
        $content = $this->generateJobClass($name, (int) $tries);

        // Write the file
        if (file_put_contents($filePath, $content) === false) {
            $this->error("Failed to write file: {$filePath}");
            return self::FAILURE;
        }

        $this->success("Job scaffolded successfully!");
        $this->line("File: {$filePath}");
        $this->line('');

        $className = $this->extractClassName($name);

        $this->table(['Property', 'Value'], [
            ['Queue', $queue],
            ['Max Attempts', $tries],
        ]);

        $this->line('');
        $this->info('Next steps:');
        $this->line('1. Implement your job logic in the handle() method');
        $this->line('2. Optionally implement the failed() method for error handling');
        $this->line('3. Dispatch the job using the queue manager');
        $this->line('');
        $this->line('Example dispatch:');
        $this->line("  \$queue->push({$className}::class, ['key' => 'value'], '{$queue}');");
        $this->line('');
        $this->line('Example with delay:');
        $this->line("  \$queue->later(60, {$className}::class, \$data, '{$queue}');");

        return self::SUCCESS;
    }

    /**
     * Normalize the job name
     */
    private function normalizeJobName(string $name): string
    {
        // Remove .php extension if provided
        $name = preg_replace('/\.php$/', '', $name) ?? $name;

        // Ensure PascalCase for each path segment
        $parts = explode('/', str_replace('\\', '/', $name));
        $parts = array_map(fn($part) => ucfirst($part), $parts);
        $name = implode('/', $parts);

        // Remove Job suffix if present (we'll add it only for display, not in class name)
        // Jobs don't need a "Job" suffix by convention
        return $name;
    }

    /**
     * Validate the class name
     */
    private function isValidClassName(string $name): bool
    {
        $parts = explode('/', str_replace('\\', '/', $name));

        foreach ($parts as $part) {
            if (preg_match('/^[A-Z][a-zA-Z0-9]*$/', $part) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the default path for job files
     */
    private function getDefaultJobPath(): string
    {
        // Check if we're in an app context or framework context
        $appPath = base_path($this->getContext(), 'app/Jobs');
        $srcPath = base_path($this->getContext(), 'src/Jobs');

        if (is_dir(base_path($this->getContext(), 'app'))) {
            return $appPath;
        }

        return $srcPath;
    }

    /**
     * Build the full file path from the base path and name
     */
    private function buildFilePath(string $basePath, string $name): string
    {
        // Handle nested namespaces (e.g., Payment/ProcessPayment)
        $name = str_replace('\\', '/', $name);

        return rtrim($basePath, '/') . '/' . $name . '.php';
    }

    /**
     * Extract class name from potentially nested path
     */
    private function extractClassName(string $name): string
    {
        $parts = explode('/', str_replace('\\', '/', $name));
        return end($parts);
    }

    /**
     * Generate the job class content
     */
    private function generateJobClass(string $name, int $tries): string
    {
        $className = $this->extractClassName($name);
        $namespace = $this->buildNamespace($name);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Queue\Job;

/**
 * {$className} Job
 *
 * Queue job for processing asynchronous tasks. Push it by class name:
 *   \$queue->push({$className}::class, ['key' => 'value'], 'queue-name');
 *
 * @package {$namespace}
 */
class {$className} extends Job
{
    /**
     * The worker builds the job with its pushed data and the app context; a custom
     * constructor must keep this signature and pass both on.
     *
     * @param array<string, mixed> \$data Job data
     */
    public function __construct(array \$data = [], ?ApplicationContext \$context = null)
    {
        parent::__construct(\$data, \$context);
    }

    /**
     * The maximum number of attempts (a maxAttempts pushed with the data overrides it;
     * queue:work --tries caps it)
     *
     * @return int Max attempts
     */
    public function getMaxAttempts(): int
    {
        return {$tries};
    }

    /**
     * Execute the job
     *
     * @return void
     */
    public function handle(): void
    {
        // Get job data
        \$data = \$this->getData();

        // Implement your job logic here; \$this->context is the app context.
        // Example:
        // \$userId = \$data['user_id'] ?? null;
        // if (\$userId !== null) {
        //     db(\$this->context)->table('users')->where('uuid', '=', \$userId)->update(['synced' => true]);
        // }
    }

    /**
     * Handle a job failure
     *
     * Called when the job fails after all retry attempts.
     *
     * @param \Exception \$exception The exception that caused the failure
     * @return void
     */
    public function failed(\Exception \$exception): void
    {
        // Log the failure or notify administrators

        parent::failed(\$exception);
    }
}

PHP;
    }

    /**
     * Build the namespace from the class name
     */
    private function buildNamespace(string $name): string
    {
        // Check if we're in an app or framework context
        $baseNamespace = is_dir(base_path($this->getContext(), 'app'))
            ? 'App\\Jobs'
            : 'Glueful\\Jobs';

        // Handle nested paths
        $parts = explode('/', str_replace('\\', '/', $name));

        // Remove the class name from parts
        array_pop($parts);

        if ($parts !== []) {
            return $baseNamespace . '\\' . implode('\\', $parts);
        }

        return $baseNamespace;
    }

    /**
     * Get detailed help text
     */
    private function getDetailedHelp(): string
    {
        return <<<HELP
Scaffold a new queue job class extending Glueful's Job base class.

Queue jobs allow you to defer time-consuming tasks to be processed
asynchronously, improving application responsiveness.

Examples:
  php glueful scaffold:job ProcessPayment
  php glueful scaffold:job SendNewsletter --queue=emails
  php glueful scaffold:job ImportData --tries=5

The generated class will be placed in app/Jobs/ (or src/Jobs/
for framework development).

Options:
  --queue     Queue used in the printed dispatch example (default: 'default');
              the queue is chosen when the job is pushed
  --tries     Max attempts, from getMaxAttempts() (default: 3)

Retry delays come from queue.workers.performance.backoff_* config.

Features:
  - Automatic UUID generation
  - Per-job max attempts
  - Failure handling hooks
  - Serialization support
  - Batch job support

Dispatching jobs:
  // Immediate dispatch
  \$queue->push(ProcessPayment::class, ['order_id' => 123]);

  // Delayed dispatch (run in 5 minutes)
  \$queue->later(300, ProcessPayment::class, \$data);

  // Dispatch to specific queue
  \$queue->push(ProcessPayment::class, \$data, 'payments');
HELP;
    }
}
