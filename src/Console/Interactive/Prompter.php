<?php

declare(strict_types=1);

namespace Glueful\Console\Interactive;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\ChoiceQuestion;

/**
 * Prompter - Fluent interface for interactive CLI prompts
 *
 * Provides a clean API for asking questions in CLI commands
 * with automatic fallback for non-interactive mode.
 *
 * @example
 * $prompter = new Prompter($helper, $input, $output);
 *
 * // Text input with validation
 * $name = $prompter->ask('What is your name?', 'Guest');
 *
 * // Confirmation
 * if ($prompter->confirm('Continue?', true)) { ... }
 *
 * // Choice selection
 * $color = $prompter->choice('Pick a color', ['red', 'green', 'blue']);
 *
 * @package Glueful\Console\Interactive
 */
class Prompter
{
    private QuestionHelper $helper;
    private InputInterface $input;
    private OutputInterface $output;

    /**
     * Create a new Prompter instance
     *
     * @param QuestionHelper $helper Symfony question helper
     * @param InputInterface $input Console input
     * @param OutputInterface $output Console output
     */
    public function __construct(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output
    ) {
        $this->helper = $helper;
        $this->input = $input;
        $this->output = $output;
    }

    /**
     * Check if running in interactive mode
     *
     * Returns false when --no-interaction flag is used.
     *
     * @return bool True if interactive mode is enabled
     */
    public function isInteractive(): bool
    {
        return $this->input->isInteractive();
    }

    /**
     * Ask for text input
     *
     * In non-interactive mode, returns the default value.
     *
     * @param string $question The question to ask
     * @param string|null $default Default value
     * @param callable|null $validator Validation callback (throw exception on invalid)
     * @return string|null The answer or default
     */
    public function ask(
        string $question,
        ?string $default = null,
        ?callable $validator = null
    ): ?string {
        if (!$this->isInteractive()) {
            return $default;
        }

        $prompt = $this->formatQuestion($question, $default);
        $questionObj = new Question($prompt, $default);

        if ($validator !== null) {
            $questionObj->setValidator($validator);
        }

        return $this->helper->ask($this->input, $this->output, $questionObj);
    }

    /**
     * Ask for required text input
     *
     * Continues prompting until a non-empty value is provided.
     *
     * @param string $question The question to ask
     * @param string|null $default Default value
     * @return string The answer (never null when interactive)
     */
    public function askRequired(string $question, ?string $default = null): string
    {
        return $this->ask($question, $default, function ($answer) {
            if ($answer === null || trim($answer) === '') {
                throw new \RuntimeException('This value is required.');
            }
            return $answer;
        }) ?? '';
    }

    /**
     * Ask for secret input (password)
     *
     * Hides user input. In non-interactive mode, returns null.
     *
     * @param string $question The question to ask
     * @param callable|null $validator Validation callback
     * @return string|null The answer
     */
    public function secret(string $question, ?callable $validator = null): ?string
    {
        if (!$this->isInteractive()) {
            return null;
        }

        $prompt = $this->formatQuestion($question);
        $questionObj = new Question($prompt);
        $questionObj->setHidden(true);
        $questionObj->setHiddenFallback(false);

        if ($validator !== null) {
            $questionObj->setValidator($validator);
        }

        return $this->helper->ask($this->input, $this->output, $questionObj);
    }

    /**
     * Ask for confirmation (yes/no)
     *
     * In non-interactive mode, returns the default value.
     *
     * @param string $question The question to ask
     * @param bool $default Default value (true = yes, false = no)
     * @return bool The answer
     */
    public function confirm(string $question, bool $default = true): bool
    {
        if (!$this->isInteractive()) {
            return $default;
        }

        $defaultStr = $default ? 'yes' : 'no';
        $prompt = $this->formatQuestion($question, $defaultStr);

        $questionObj = new ConfirmationQuestion($prompt, $default);

        return (bool) $this->helper->ask($this->input, $this->output, $questionObj);
    }

    /**
     * Ask to choose from options
     *
     * In non-interactive mode, returns the default or first choice.
     *
     * @param string $question The question to ask
     * @param array<string|int, string> $choices Available choices
     * @param string|int|null $default Default choice (key or value)
     * @return string|int The selected choice key
     */
    public function choice(
        string $question,
        array $choices,
        string|int|null $default = null
    ): string|int {
        if (!$this->isInteractive()) {
            return $default ?? array_key_first($choices) ?? 0;
        }

        $prompt = $this->formatQuestion($question, (string) $default);
        $questionObj = new ChoiceQuestion($prompt, $choices, $default);
        $questionObj->setErrorMessage('Choice "%s" is invalid.');

        return $this->helper->ask($this->input, $this->output, $questionObj);
    }

    /**
     * Ask to choose multiple options
     *
     * In non-interactive mode, returns the defaults or empty array.
     *
     * @param string $question The question to ask
     * @param array<string|int, string> $choices Available choices
     * @param array<string|int>|null $defaults Default choices
     * @return array<string|int> The selected choices
     */
    public function multiChoice(
        string $question,
        array $choices,
        ?array $defaults = null
    ): array {
        if (!$this->isInteractive()) {
            return $defaults ?? [];
        }

        $defaultStr = $defaults !== null ? implode(',', $defaults) : null;
        $prompt = $this->formatQuestion($question, $defaultStr);

        $questionObj = new ChoiceQuestion($prompt, $choices, $defaultStr);
        $questionObj->setMultiselect(true);
        $questionObj->setErrorMessage('Choice "%s" is invalid.');

        $result = $this->helper->ask($this->input, $this->output, $questionObj);

        return is_array($result) ? $result : [$result];
    }

    /**
     * Ask with auto-completion
     *
     * Provides suggestions as the user types.
     *
     * @param string $question The question to ask
     * @param array<string> $suggestions Auto-completion suggestions
     * @param string|null $default Default value
     * @return string|null The answer
     */
    public function suggest(
        string $question,
        array $suggestions,
        ?string $default = null
    ): ?string {
        if (!$this->isInteractive()) {
            return $default;
        }

        $prompt = $this->formatQuestion($question, $default);
        $questionObj = new Question($prompt, $default);
        $questionObj->setAutocompleterValues($suggestions);

        return $this->helper->ask($this->input, $this->output, $questionObj);
    }

    /**
     * Display an info message
     *
     * @param string $message The message to display
     */
    public function info(string $message): void
    {
        $this->output->writeln("<info>{$message}</info>");
    }

    /**
     * Display a warning message
     *
     * @param string $message The message to display
     */
    public function warn(string $message): void
    {
        $this->output->writeln("<comment>{$message}</comment>");
    }

    /**
     * Display an error message
     *
     * @param string $message The message to display
     */
    public function error(string $message): void
    {
        $this->output->writeln("<error>{$message}</error>");
    }

    /**
     * Display a success message with checkmark
     *
     * @param string $message The message to display
     */
    public function success(string $message): void
    {
        $this->output->writeln("<info>✓</info> {$message}");
    }

    /**
     * Display a line of text
     *
     * @param string $message The message to display
     */
    public function line(string $message = ''): void
    {
        $this->output->writeln($message);
    }

    /**
     * Add newlines
     *
     * @param int $count Number of newlines to add
     */
    public function newLine(int $count = 1): void
    {
        $this->output->write(str_repeat(PHP_EOL, $count));
    }

    /**
     * Get the output interface
     *
     * @return OutputInterface
     */
    public function getOutput(): OutputInterface
    {
        return $this->output;
    }

    /**
     * Get the input interface
     *
     * @return InputInterface
     */
    public function getInput(): InputInterface
    {
        return $this->input;
    }

    /**
     * Format question with default value hint
     *
     * @param string $question The question text
     * @param string|null $default The default value to display
     * @return string Formatted question string
     */
    private function formatQuestion(string $question, ?string $default = null): string
    {
        $suffix = $default !== null ? " [<comment>{$default}</comment>]" : '';
        return " <info>?</info> {$question}{$suffix}: ";
    }
}
