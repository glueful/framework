<?php

declare(strict_types=1);

namespace Glueful\Console\Interactive\Progress;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Spinner animation for indeterminate progress
 *
 * Displays an animated spinner while a task is running.
 * Useful for operations where total progress is unknown.
 *
 * @example
 * $spinner = new Spinner($output, 'Connecting to server...');
 *
 * $result = $spinner->run(function() {
 *     return fetchDataFromServer();
 * });
 *
 * // Or manual control:
 * $spinner->start();
 * while ($processing) {
 *     doWork();
 *     $spinner->tick();
 * }
 * $spinner->success('Connected!');
 *
 * @package Glueful\Console\Interactive\Progress
 */
class Spinner
{
    private OutputInterface $output;
    private string $message;
    private int $frame = 0;
    private bool $running = false;

    /**
     * Available spinner animation styles
     */
    public const STYLES = [
        'dots' => ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'],
        'line' => ['|', '/', '-', '\\'],
        'arrows' => ['←', '↖', '↑', '↗', '→', '↘', '↓', '↙'],
        'bouncing' => ['⠁', '⠂', '⠄', '⡀', '⢀', '⠠', '⠐', '⠈'],
        'growing' => ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█', '▇', '▆', '▅', '▄', '▃', '▂'],
        'circle' => ['◐', '◓', '◑', '◒'],
        'square' => ['◰', '◳', '◲', '◱'],
        'toggle' => ['⊶', '⊷'],
        'simple' => ['.  ', '.. ', '...', ' ..', '  .', '   '],
    ];

    /**
     * Current animation frames
     *
     * @var array<string>
     */
    private array $frames;

    /**
     * Create a new spinner
     *
     * @param OutputInterface $output Console output
     * @param string $message Message to display
     * @param string $style Animation style (see STYLES constant)
     */
    public function __construct(
        OutputInterface $output,
        string $message = 'Loading...',
        string $style = 'dots'
    ) {
        $this->output = $output;
        $this->message = $message;
        $this->frames = self::STYLES[$style] ?? self::STYLES['dots'];
    }

    /**
     * Set the message to display
     *
     * @param string $message New message
     * @return self
     */
    public function setMessage(string $message): self
    {
        $this->message = $message;
        return $this;
    }

    /**
     * Set the animation style
     *
     * @param string $style Style name from STYLES constant
     * @return self
     */
    public function setStyle(string $style): self
    {
        $this->frames = self::STYLES[$style] ?? self::STYLES['dots'];
        return $this;
    }

    /**
     * Set custom animation frames
     *
     * @param array<string> $frames Custom frame characters
     * @return self
     */
    public function setFrames(array $frames): self
    {
        $this->frames = $frames;
        return $this;
    }

    /**
     * Render the current frame
     *
     * Clears the line and writes the current animation frame.
     */
    public function render(): void
    {
        $frameCount = count($this->frames);
        if ($frameCount === 0) {
            return;
        }

        $frame = $this->frames[$this->frame % $frameCount];
        $this->frame++;

        // Clear line and write new frame
        $this->output->write("\r\033[K");
        $this->output->write(" <info>{$frame}</info> {$this->message}");
    }

    /**
     * Run a callback with spinner animation
     *
     * The spinner will automatically start and stop around the callback.
     * Note: Animation only updates at start and end since PHP is synchronous.
     *
     * @template T
     * @param callable(): T $callback The task to run
     * @return T The callback's return value
     */
    public function run(callable $callback): mixed
    {
        $this->start();

        try {
            return $callback();
        } finally {
            $this->stop();
        }
    }

    /**
     * Run a callback with spinner and success message
     *
     * @template T
     * @param callable(): T $callback The task to run
     * @param string $successMessage Message to show on completion
     * @return T The callback's return value
     */
    public function runWithSuccess(callable $callback, string $successMessage): mixed
    {
        $this->start();

        try {
            $result = $callback();
            $this->success($successMessage);
            return $result;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            throw $e;
        }
    }

    /**
     * Start the spinner (manual control)
     *
     * Use tick() to advance the animation.
     */
    public function start(): void
    {
        $this->running = true;
        $this->render();
    }

    /**
     * Tick the spinner (advance one frame)
     *
     * Call this periodically to animate the spinner.
     */
    public function tick(): void
    {
        if ($this->running) {
            $this->render();
        }
    }

    /**
     * Update message and tick
     *
     * @param string $message New message
     */
    public function update(string $message): void
    {
        $this->message = $message;
        $this->tick();
    }

    /**
     * Stop the spinner
     *
     * Clears the spinner line.
     */
    public function stop(): void
    {
        $this->running = false;
        $this->output->write("\r\033[K");
    }

    /**
     * Stop with success message
     *
     * @param string $message Success message
     */
    public function success(string $message): void
    {
        $this->stop();
        $this->output->writeln(" <info>✓</info> {$message}");
    }

    /**
     * Stop with error message
     *
     * @param string $message Error message
     */
    public function error(string $message): void
    {
        $this->stop();
        $this->output->writeln(" <error>✗</error> {$message}");
    }

    /**
     * Stop with warning message
     *
     * @param string $message Warning message
     */
    public function warn(string $message): void
    {
        $this->stop();
        $this->output->writeln(" <comment>⚠</comment> {$message}");
    }

    /**
     * Stop with info message
     *
     * @param string $message Info message
     */
    public function info(string $message): void
    {
        $this->stop();
        $this->output->writeln(" <info>ℹ</info> {$message}");
    }

    /**
     * Check if spinner is currently running
     *
     * @return bool True if running
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Get available style names
     *
     * @return array<string> List of style names
     */
    public static function getAvailableStyles(): array
    {
        return array_keys(self::STYLES);
    }
}
