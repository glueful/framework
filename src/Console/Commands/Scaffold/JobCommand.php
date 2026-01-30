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
                'The queue name for this job',
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
                'backoff',
                null,
                InputOption::VALUE_OPTIONAL,
                'Seconds to wait before retry',
                '60'
            )
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_OPTIONAL,
                'Job timeout in seconds',
                '60'
            )
            ->addOption(
                'unique',
                'u',
                InputOption::VALUE_NONE,
                'Make the job unique (prevent duplicate jobs)'
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
        /** @var string $backoff */
        $backoff = $input->getOption('backoff') ?? '60';
        /** @var string $timeout */
        $timeout = $input->getOption('timeout') ?? '60';
        /** @var bool $unique */
        $unique = (bool) $input->getOption('unique');

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
        $content = $this->generateJobClass(
            $name,
            $queue,
            (int) $tries,
            (int) $backoff,
            (int) $timeout,
            $unique
        );

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
            ['Backoff', "{$backoff}s"],
            ['Timeout', "{$timeout}s"],
            ['Unique', $unique ? 'Yes' : 'No'],
        ]);

        $this->line('');
        $this->info('Next steps:');
        $this->line('1. Implement your job logic in the handle() method');
        $this->line('2. Optionally implement the failed() method for error handling');
        $this->line('3. Dispatch the job using the queue manager');
        $this->line('');
        $this->line('Example dispatch:');
        $this->line("  \$queue->push(new {$className}(['key' => 'value']));");
        $this->line('');
        $this->line('Example with delay:');
        $this->line("  \$queue->later(60, new {$className}(\$data));");

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
            if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $part)) {
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
    private function generateJobClass(
        string $name,
        string $queue,
        int $tries,
        int $backoff,
        int $timeout,
        bool $unique
    ): string {
        $className = $this->extractClassName($name);
        $namespace = $this->buildNamespace($name);

        $uniqueTrait = $unique ? $this->generateUniqueTrait() : '';
        $uniqueUse = $unique ? "\n    use ShouldBeUnique;" : '';
        $uniqueImport = $unique ? "use Glueful\\Queue\\Contracts\\ShouldBeUnique;\n" : '';

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Glueful\Queue\Job;
{$uniqueImport}
/**
 * {$className} Job
 *
 * Queue job for processing asynchronous tasks.
 *
 * @package {$namespace}
 */
class {$className} extends Job{$uniqueUse}
{
    /**
     * The queue this job should run on
     */
    protected ?string \$queue = '{$queue}';

    /**
     * Create a new job instance
     *
     * @param array<string, mixed> \$data Job data
     */
    public function __construct(array \$data = [])
    {
        parent::__construct(\$data);
    }

    /**
     * Get the maximum number of attempts
     *
     * @return int Max attempts
     */
    public function getMaxAttempts(): int
    {
        return {$tries};
    }

    /**
     * Get the job timeout in seconds
     *
     * @return int Timeout in seconds
     */
    public function getTimeout(): int
    {
        return {$timeout};
    }

    /**
     * Get the number of seconds to wait before retrying
     *
     * @return int Backoff in seconds
     */
    public function getBackoff(): int
    {
        return {$backoff};
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

        // Implement your job logic here
        // Example:
        // \$userId = \$data['user_id'] ?? null;
        // if (\$userId) {
        //     \$this->processUser(\$userId);
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
        // Example:
        // Log::error("Job {$className} failed", [
        //     'uuid' => \$this->getUuid(),
        //     'data' => \$this->getData(),
        //     'exception' => \$exception->getMessage(),
        // ]);

        parent::failed(\$exception);
    }{$uniqueTrait}
}

PHP;
    }

    /**
     * Generate unique trait implementation
     */
    private function generateUniqueTrait(): string
    {
        return <<<'PHP'


    /**
     * Get the unique ID for this job
     *
     * Override this method to define how job uniqueness is determined.
     *
     * @return string|null Unique identifier
     */
    public function uniqueId(): ?string
    {
        // Return a unique identifier based on job data
        // Example: return $this->getData()['user_id'] ?? null;
        return null;
    }

    /**
     * Get the number of seconds until the unique lock is released
     *
     * @return int Lock duration in seconds
     */
    public function uniqueFor(): int
    {
        return 3600; // 1 hour
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
  php glueful scaffold:job ImportData --tries=5 --backoff=120
  php glueful scaffold:job GenerateReport --unique

The generated class will be placed in app/Jobs/ (or src/Jobs/
for framework development).

Options:
  --queue     Queue name (default: 'default')
  --tries     Number of retry attempts (default: 3)
  --backoff   Seconds between retries (default: 60)
  --timeout   Job timeout in seconds (default: 60)
  --unique    Make the job unique to prevent duplicates

Features:
  - Automatic UUID generation
  - Configurable retry behavior
  - Failure handling hooks
  - Serialization support
  - Batch job support

Dispatching jobs:
  // Immediate dispatch
  \$queue->push(new ProcessPayment(['order_id' => 123]));

  // Delayed dispatch (run in 5 minutes)
  \$queue->later(300, new ProcessPayment(\$data));

  // Dispatch to specific queue
  \$job = new ProcessPayment(\$data);
  \$job->setQueue('payments');
  \$queue->push(\$job);
HELP;
    }
}
