<?php

declare(strict_types=1);

namespace Glueful\Console\Interactive\Progress;

use Symfony\Component\Console\Helper\ProgressBar as SymfonyProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Enhanced Progress Bar wrapper
 *
 * Provides a fluent interface for Symfony's progress bar with
 * additional convenience methods for common operations.
 *
 * @example
 * $progress = new ProgressBar($output, 100);
 * $progress->setFormat('verbose')->start();
 *
 * foreach ($items as $item) {
 *     $progress->setMessage("Processing: {$item->name}");
 *     processItem($item);
 *     $progress->advance();
 * }
 *
 * $progress->finish();
 *
 * @package Glueful\Console\Interactive\Progress
 */
class ProgressBar
{
    private SymfonyProgressBar $bar;
    private OutputInterface $output;

    /**
     * Pre-defined format strings
     */
    public const FORMAT_NORMAL = ' %current%/%max% [%bar%] %percent:3s%%';
    public const FORMAT_VERBOSE = ' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%';
    public const FORMAT_VERY_VERBOSE = ' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%';
    public const FORMAT_DEBUG = ' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%';
    public const FORMAT_MINIMAL = ' [%bar%] %percent:3s%%';
    public const FORMAT_WITH_MESSAGE = " %message%\n %current%/%max% [%bar%] %percent:3s%%";

    /**
     * Create a new progress bar
     *
     * @param OutputInterface $output Console output
     * @param int $max Maximum number of steps (0 for indeterminate)
     */
    public function __construct(OutputInterface $output, int $max = 0)
    {
        $this->output = $output;
        $this->bar = new SymfonyProgressBar($output, $max);

        // Configure default format based on verbosity
        $this->bar->setFormat(self::FORMAT_VERY_VERBOSE);
    }

    /**
     * Set the maximum number of steps
     *
     * @param int $max Maximum steps
     * @return self
     */
    public function setMaxSteps(int $max): self
    {
        $this->bar->setMaxSteps($max);
        return $this;
    }

    /**
     * Set a custom format string
     *
     * Available placeholders:
     * - %current% - Current step
     * - %max% - Maximum steps
     * - %bar% - The progress bar
     * - %percent% - Percentage complete
     * - %elapsed% - Time elapsed
     * - %estimated% - Estimated remaining time
     * - %memory% - Memory usage
     * - %message% - Custom message
     *
     * @param string $format Format string
     * @return self
     */
    public function setFormat(string $format): self
    {
        $this->bar->setFormat($format);
        return $this;
    }

    /**
     * Use a predefined format
     *
     * @param string $name Format name: 'normal', 'verbose', 'very_verbose', 'debug', 'minimal', 'with_message'
     * @return self
     */
    public function useFormat(string $name): self
    {
        $formats = [
            'normal' => self::FORMAT_NORMAL,
            'verbose' => self::FORMAT_VERBOSE,
            'very_verbose' => self::FORMAT_VERY_VERBOSE,
            'debug' => self::FORMAT_DEBUG,
            'minimal' => self::FORMAT_MINIMAL,
            'with_message' => self::FORMAT_WITH_MESSAGE,
        ];

        if (isset($formats[$name])) {
            $this->bar->setFormat($formats[$name]);
        }

        return $this;
    }

    /**
     * Set the bar width (number of characters)
     *
     * @param int $width Width in characters
     * @return self
     */
    public function setBarWidth(int $width): self
    {
        $this->bar->setBarWidth($width);
        return $this;
    }

    /**
     * Set the bar character (filled portion)
     *
     * @param string $char Character to use
     * @return self
     */
    public function setBarCharacter(string $char): self
    {
        $this->bar->setBarCharacter($char);
        return $this;
    }

    /**
     * Set the progress character (current position)
     *
     * @param string $char Character to use
     * @return self
     */
    public function setProgressCharacter(string $char): self
    {
        $this->bar->setProgressCharacter($char);
        return $this;
    }

    /**
     * Set the empty bar character (unfilled portion)
     *
     * @param string $char Character to use
     * @return self
     */
    public function setEmptyBarCharacter(string $char): self
    {
        $this->bar->setEmptyBarCharacter($char);
        return $this;
    }

    /**
     * Start the progress bar
     *
     * @param int|null $max Override maximum steps
     * @return self
     */
    public function start(?int $max = null): self
    {
        $this->bar->start($max);
        return $this;
    }

    /**
     * Advance the progress bar
     *
     * @param int $step Number of steps to advance
     * @return self
     */
    public function advance(int $step = 1): self
    {
        $this->bar->advance($step);
        return $this;
    }

    /**
     * Set the current progress
     *
     * @param int $step Current step number
     * @return self
     */
    public function setProgress(int $step): self
    {
        $this->bar->setProgress($step);
        return $this;
    }

    /**
     * Set a message to display
     *
     * Requires a format with %message% placeholder.
     *
     * @param string $message Message text
     * @param string $name Placeholder name (default: 'message')
     * @return self
     */
    public function setMessage(string $message, string $name = 'message'): self
    {
        $this->bar->setMessage($message, $name);
        return $this;
    }

    /**
     * Finish the progress bar
     *
     * @return self
     */
    public function finish(): self
    {
        $this->bar->finish();
        $this->output->writeln('');
        return $this;
    }

    /**
     * Clear the progress bar
     *
     * @return self
     */
    public function clear(): self
    {
        $this->bar->clear();
        return $this;
    }

    /**
     * Display the progress bar
     *
     * @return self
     */
    public function display(): self
    {
        $this->bar->display();
        return $this;
    }

    /**
     * Get the current progress value
     *
     * @return int
     */
    public function getProgress(): int
    {
        return $this->bar->getProgress();
    }

    /**
     * Get the maximum steps
     *
     * @return int
     */
    public function getMaxSteps(): int
    {
        return $this->bar->getMaxSteps();
    }

    /**
     * Get the underlying Symfony progress bar
     *
     * @return SymfonyProgressBar
     */
    public function getSymfonyProgressBar(): SymfonyProgressBar
    {
        return $this->bar;
    }

    /**
     * Iterate over items with automatic progress tracking
     *
     * @template T
     * @param iterable<T> $items Items to iterate
     * @return \Generator<int|string, T>
     */
    public function iterate(iterable $items): \Generator
    {
        if (is_countable($items)) {
            $this->setMaxSteps(count($items));
        }

        $this->start();

        foreach ($items as $key => $item) {
            yield $key => $item;
            $this->advance();
        }

        $this->finish();
    }

    /**
     * Process items with a callback and progress tracking
     *
     * @template T
     * @template R
     * @param iterable<T> $items Items to process
     * @param callable(T): R $callback Processing callback
     * @return array<R> Results from callback
     */
    public function map(iterable $items, callable $callback): array
    {
        $results = [];

        foreach ($this->iterate($items) as $key => $item) {
            $results[$key] = $callback($item);
        }

        return $results;
    }

    /**
     * Create a progress bar for a specific count with a processing callback
     *
     * @param int $count Number of items
     * @param callable(int): void $callback Callback receiving current index
     * @return void
     */
    public function times(int $count, callable $callback): void
    {
        $this->setMaxSteps($count)->start();

        for ($i = 0; $i < $count; $i++) {
            $callback($i);
            $this->advance();
        }

        $this->finish();
    }
}
