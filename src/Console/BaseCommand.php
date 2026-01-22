<?php

namespace Glueful\Console;

use Glueful\Console\Interactive\Prompter;
use Glueful\Console\Interactive\Progress\ProgressBar as EnhancedProgressBar;
use Glueful\Console\Interactive\Progress\Spinner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\ProgressBar;
use Psr\Container\ContainerInterface;

/**
 * Glueful Console Command Base Class
 *
 * Enhanced base class for console commands with Glueful integration:
 * - Provides DI container access
 * - Includes Glueful-specific styling and helpers
 * - Maintains compatibility with legacy command patterns
 * - Adds enhanced output formatting and interactivity
 *
 * @package Glueful\Console
 */
abstract class BaseCommand extends Command
{
    /** @var ContainerInterface DI Container */
    protected ContainerInterface $container;

    /** @var SymfonyStyle Enhanced output formatter */
    protected SymfonyStyle $io;

    /** @var InputInterface Command input */
    protected InputInterface $input;

    /** @var OutputInterface Command output */
    protected OutputInterface $output;

    /** @var Prompter|null Interactive prompter instance */
    protected ?Prompter $prompter = null;

    /**
     * Initialize Command
     *
     * Sets up command with DI container:
     * - Resolves container from global container function
     * - Configures command properties
     * - Calls parent constructor
     *
     * @param ContainerInterface|null $container DI Container instance
     */
    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container ?? container();
        parent::__construct();
    }

    /**
     * Initialize Command Execution
     *
     * Sets up execution environment:
     * - Initializes SymfonyStyle for enhanced output
     * - Stores input/output references
     * - Configures interactive helpers
     *
     * @param InputInterface $input Command input
     * @param OutputInterface $output Command output
     * @return void
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->input = $input;
        $this->output = $output;
        $this->io = new SymfonyStyle($input, $output);
    }

    /**
     * Get DI Container
     *
     * Provides access to the dependency injection container:
     * - Enables service resolution
     * - Allows access to application services
     * - Maintains container lifecycle
     *
     * @return ContainerInterface
     */
    protected function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * Get Service from Container
     *
     * Convenience method for service resolution:
     * - Resolves service by class name or identifier
     * - Handles dependency injection
     * - Provides type-safe service access
     *
     * @template T of object
     * @param class-string<T> $serviceId Service identifier
     * @return T Resolved service instance
     */
    protected function getService(string $serviceId)
    {
        return $this->container->get($serviceId);
    }

    /**
     * Get Service with Dynamic ID
     *
     * Resolves services using dynamic string identifiers that can't use
     * the template type system. Uses reflection to bypass PHPStan template
     * type checking for debugging and validation scenarios.
     *
     * @param string $serviceId Dynamic service identifier
     * @return mixed The resolved service instance
     */
    protected function getServiceDynamic(string $serviceId): mixed
    {
        // Use reflection to bypass template type checking
        $reflectionClass = new \ReflectionClass($this->container);
        $getMethod = $reflectionClass->getMethod('get');

        return $getMethod->invoke($this->container, $serviceId);
    }

    // =====================================
    // Enhanced Output Methods
    // =====================================

    /**
     * Display Success Message
     *
     * Shows a formatted success message with green styling:
     * - Uses SymfonyStyle for consistent formatting
     * - Includes success icon
     * - Maintains legacy compatibility
     *
     * @param string $message Success message
     * @return void
     */
    protected function success(string $message): void
    {
        $this->io->success($message);
    }

    /**
     * Display Error Message
     *
     * Shows a formatted error message with red styling:
     * - Uses SymfonyStyle for consistent formatting
     * - Includes error icon
     * - Maintains legacy compatibility
     *
     * @param string $message Error message
     * @return void
     */
    protected function error(string $message): void
    {
        $this->io->error($message);
    }

    /**
     * Display Warning Message
     *
     * Shows a formatted warning message with yellow styling:
     * - Uses SymfonyStyle for consistent formatting
     * - Includes warning icon
     * - Maintains legacy compatibility
     *
     * @param string $message Warning message
     * @return void
     */
    protected function warning(string $message): void
    {
        $this->io->warning($message);
    }

    /**
     * Display Info Message
     *
     * Shows a formatted info message:
     * - Uses SymfonyStyle for consistent formatting
     * - Maintains legacy compatibility
     * - Provides clean information display
     *
     * @param string $message Info message
     * @return void
     */
    protected function info(string $message): void
    {
        $this->io->info($message);
    }

    /**
     * Display Note/Tip Message
     *
     * Shows a formatted note message:
     * - Uses SymfonyStyle for consistent formatting
     * - Provides helpful tips and notes
     * - Maintains legacy "tip" method compatibility
     *
     * @param string $message Note/tip message
     * @return void
     */
    protected function note(string $message): void
    {
        $this->io->note($message);
    }

    /**
     * Display Tip Message (Legacy Compatibility)
     *
     * Alias for note() method to maintain legacy compatibility:
     * - Provides same functionality as legacy tip()
     * - Uses enhanced SymfonyStyle formatting
     *
     * @param string $message Tip message
     * @return void
     */
    protected function tip(string $message): void
    {
        $this->note("Tip: " . $message);
    }

    /**
     * Display Plain Line
     *
     * Shows a plain text line:
     * - Maintains legacy compatibility
     * - Uses SymfonyStyle writeln for consistency
     *
     * @param string $message Line message
     * @return void
     */
    protected function line(string $message = ''): void
    {
        $this->io->writeln($message);
    }

    // =====================================
    // Enhanced Interactive Methods
    // =====================================

    /**
     * Ask Confirmation Question
     *
     * Prompts user for yes/no confirmation:
     * - Uses SymfonyStyle for consistent formatting
     * - Handles user input validation
     * - Provides default value support
     *
     * @param string $question Question text
     * @param bool $default Default answer (true = yes, false = no)
     * @return bool User's answer
     */
    protected function confirm(string $question, bool $default = false): bool
    {
        return $this->io->confirm($question, $default);
    }

    /**
     * Ask Text Question
     *
     * Prompts user for text input:
     * - Uses SymfonyStyle for consistent formatting
     * - Supports default values
     * - Handles validation
     *
     * @param string $question Question text
     * @param string|null $default Default value
     * @return string User's answer
     */
    protected function ask(string $question, ?string $default = null): string
    {
        return $this->io->ask($question, $default);
    }

    /**
     * Ask Secret Question
     *
     * Prompts user for hidden input (passwords, etc.):
     * - Hides user input
     * - Uses SymfonyStyle for consistent formatting
     * - Provides secure input handling
     *
     * @param string $question Question text
     * @return string User's answer
     */
    protected function secret(string $question): string
    {
        $input = $this->io->askHidden($question);

        // Handle null input (user cancelled, no input, etc.)
        if ($input === null) {
            throw new \RuntimeException('Input cancelled or no input provided', 400);
        }

        return $input;
    }

    /**
     * Display Choice Menu
     *
     * Shows a selection menu with options:
     * - Uses SymfonyStyle for consistent formatting
     * - Handles user selection
     * - Supports default values
     *
     * @param string $question Question text
     * @param array<string> $choices Available choices
     * @param string|null $default Default choice
     * @return string Selected choice
     */
    protected function choice(string $question, array $choices, ?string $default = null): string
    {
        return $this->io->choice($question, $choices, $default);
    }

    // =====================================
    // Enhanced Display Methods
    // =====================================

    /**
     * Display Table
     *
     * Shows formatted table with data:
     * - Uses SymfonyStyle table formatting
     * - Supports headers and multiple rows
     * - Provides clean tabular display
     *
     * @param array<string> $headers Table headers
     * @param array<array<string>> $rows Table rows
     * @return void
     */
    protected function table(array $headers, array $rows): void
    {
        $this->io->table($headers, $rows);
    }

    /**
     * Create Progress Bar
     *
     * Creates a progress bar for long operations:
     * - Uses SymfonyStyle progress bar
     * - Supports custom step counts
     * - Provides visual feedback
     *
     * @param int $max Maximum steps
     * @return ProgressBar Progress bar instance
     */
    protected function createProgressBar(int $max = 0): ProgressBar
    {
        return $this->io->createProgressBar($max);
    }

    /**
     * Display Progress Bar
     *
     * Shows a progress bar with completion callback:
     * - Handles progress bar lifecycle
     * - Executes callback with progress updates
     * - Provides clean progress display
     *
     * @param int $steps Total number of steps
     * @param callable $callback Callback function receiving progress bar
     * @return void
     */
    protected function progressBar(int $steps, callable $callback): void
    {
        $progressBar = $this->createProgressBar($steps);
        $progressBar->start();

        $callback($progressBar);

        $progressBar->finish();
        $this->io->newLine();
    }

    // =====================================
    // Utility Methods
    // =====================================

    /**
     * Check Production Environment
     *
     * Determines if application is running in production:
     * - Reads from configuration
     * - Used for safety checks
     * - Maintains legacy compatibility
     *
     * @return bool True if production environment
     */
    protected function isProduction(): bool
    {
        return config('app.env') === 'production';
    }

    /**
     * Require Production Confirmation
     *
     * Forces confirmation for production operations:
     * - Checks environment
     * - Requires explicit confirmation
     * - Prevents accidental production changes
     *
     * @param string $operation Operation description
     * @return bool True if confirmed or not production
     */
    protected function confirmProduction(string $operation): bool
    {
        if (!$this->isProduction()) {
            return true;
        }

        $this->warning("You are about to {$operation} in PRODUCTION environment!");
        return $this->confirm("Are you sure you want to continue?", false);
    }

    // =====================================
    // Enhanced Interactive Methods
    // =====================================

    /**
     * Get the Prompter instance
     *
     * Returns a Prompter configured with the current input/output.
     * The Prompter provides a fluent API for interactive prompts.
     *
     * @return Prompter
     */
    protected function getPrompter(): Prompter
    {
        if ($this->prompter === null) {
            /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $this->prompter = new Prompter($helper, $this->input, $this->output);
        }

        return $this->prompter;
    }

    /**
     * Check if running in interactive mode
     *
     * Returns false when --no-interaction flag is used.
     *
     * @return bool True if interactive mode is enabled
     */
    protected function isInteractive(): bool
    {
        return $this->input->isInteractive();
    }

    /**
     * Ask for text input with auto-fallback
     *
     * In non-interactive mode, returns the default value.
     *
     * @param string $question Question to ask
     * @param string|null $default Default value
     * @param callable|null $validator Validation callback
     * @return string|null The answer or default
     */
    protected function prompt(
        string $question,
        ?string $default = null,
        ?callable $validator = null
    ): ?string {
        return $this->getPrompter()->ask($question, $default, $validator);
    }

    /**
     * Ask for required text input
     *
     * Continues prompting until a non-empty value is provided.
     *
     * @param string $question Question to ask
     * @param string|null $default Default value
     * @return string The answer
     */
    protected function promptRequired(string $question, ?string $default = null): string
    {
        return $this->getPrompter()->askRequired($question, $default);
    }

    /**
     * Ask to select from multiple options
     *
     * In non-interactive mode, returns the defaults or empty array.
     *
     * @param string $question Question to ask
     * @param array<string|int, string> $choices Available choices
     * @param array<string|int>|null $defaults Default selections
     * @return array<string|int> Selected choices
     */
    protected function multiChoice(
        string $question,
        array $choices,
        ?array $defaults = null
    ): array {
        return $this->getPrompter()->multiChoice($question, $choices, $defaults);
    }

    /**
     * Ask with auto-completion suggestions
     *
     * @param string $question Question to ask
     * @param array<string> $suggestions Suggestions for auto-completion
     * @param string|null $default Default value
     * @return string|null The answer
     */
    protected function suggest(
        string $question,
        array $suggestions,
        ?string $default = null
    ): ?string {
        return $this->getPrompter()->suggest($question, $suggestions, $default);
    }

    /**
     * Create an enhanced progress bar
     *
     * @param int $max Maximum steps
     * @return EnhancedProgressBar Progress bar instance
     */
    protected function createEnhancedProgressBar(int $max = 0): EnhancedProgressBar
    {
        return new EnhancedProgressBar($this->output, $max);
    }

    /**
     * Create a spinner for indeterminate progress
     *
     * @param string $message Message to display
     * @param string $style Animation style
     * @return Spinner Spinner instance
     */
    protected function createSpinner(
        string $message = 'Loading...',
        string $style = 'dots'
    ): Spinner {
        return new Spinner($this->output, $message, $style);
    }

    /**
     * Run a task with progress tracking
     *
     * @template T
     * @param iterable<T> $items Items to process
     * @param callable(T): void $callback Processing callback
     * @param string|null $message Progress message (optional)
     */
    protected function withProgress(
        iterable $items,
        callable $callback,
        ?string $message = null
    ): void {
        $progress = $this->createEnhancedProgressBar();

        if ($message !== null) {
            $progress->setFormat(EnhancedProgressBar::FORMAT_WITH_MESSAGE);
            $progress->setMessage($message);
        }

        foreach ($progress->iterate($items) as $item) {
            $callback($item);
        }
    }

    /**
     * Run a task with spinner animation
     *
     * @template T
     * @param callable(): T $callback Task to run
     * @param string $message Spinner message
     * @return T The callback's return value
     */
    protected function withSpinner(callable $callback, string $message = 'Loading...'): mixed
    {
        $spinner = $this->createSpinner($message);
        return $spinner->run($callback);
    }

    /**
     * Run a task with spinner and success message
     *
     * @template T
     * @param callable(): T $callback Task to run
     * @param string $message Spinner message
     * @param string $successMessage Success message
     * @return T The callback's return value
     */
    protected function withSpinnerSuccess(
        callable $callback,
        string $message,
        string $successMessage
    ): mixed {
        $spinner = $this->createSpinner($message);
        return $spinner->runWithSuccess($callback, $successMessage);
    }

    /**
     * Confirm before destructive operation
     *
     * Shows a warning and requires explicit confirmation.
     * In non-interactive mode, returns --force option value.
     *
     * @param string $message Warning message
     * @return bool True if confirmed
     */
    protected function confirmDestructive(string $message): bool
    {
        if (!$this->isInteractive()) {
            return (bool) ($this->input->getOption('force') ?? false);
        }

        $this->line('');
        $this->warning('This is a destructive operation.');

        return $this->confirm($message, false);
    }
}
