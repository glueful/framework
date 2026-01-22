# Interactive CLI Wizards Implementation Plan

> A comprehensive plan for adding interactive mode to CLI commands with prompts, confirmations, and progress indicators in Glueful Framework.

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Goals and Non-Goals](#goals-and-non-goals)
3. [Current State Analysis](#current-state-analysis)
4. [Architecture Design](#architecture-design)
5. [Prompter System](#prompter-system)
6. [Question Types](#question-types)
7. [Progress Indicators](#progress-indicators)
8. [Command Integration](#command-integration)
9. [Implementation Phases](#implementation-phases)
10. [Testing Strategy](#testing-strategy)
11. [API Reference](#api-reference)

---

## Executive Summary

This document outlines the implementation of an interactive CLI wizard system for Glueful Framework. The system will provide:

- **Interactive prompts** for collecting user input when arguments aren't provided
- **Confirmation dialogs** for destructive operations
- **Choice menus** for selecting from options
- **Progress bars** for long-running operations
- **Spinners** for indeterminate progress
- **`--no-interaction`** flag support for CI/CD environments

The implementation builds on Symfony Console and adds developer-friendly abstractions.

---

## Goals and Non-Goals

### Goals

- ✅ Interactive mode for scaffold commands when arguments omitted
- ✅ Confirmation prompts for destructive operations
- ✅ Choice/multi-select menus
- ✅ Progress bars with ETA
- ✅ Spinner animations for background tasks
- ✅ Consistent UX across all commands
- ✅ `--no-interaction` support for automation
- ✅ Default values with `[default]` syntax

### Non-Goals

- ❌ Full TUI (text user interface) framework
- ❌ Mouse support
- ❌ Complex form layouts
- ❌ External terminal emulator features

---

## Current State Analysis

### Existing Infrastructure

Symfony Console provides basic question helpers:

```php
// Current usage (verbose)
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\ChoiceQuestion;

$helper = $this->getHelper('question');
$question = new Question('What is your name? ');
$name = $helper->ask($input, $output, $question);
```

### Gap Analysis

| Gap | Solution |
|-----|----------|
| Verbose question setup | Fluent `Prompter` API |
| No styled prompts | Themed question rendering |
| Manual fallback handling | Auto-fallback when `--no-interaction` |
| No progress abstraction | `Progress` helper class |
| Inconsistent UX | Standardized prompt styles |

---

## Architecture Design

### Directory Structure

```
src/
├── Console/
│   ├── BaseCommand.php                    # Add prompt helpers
│   │
│   └── Interactive/                       # 📋 NEW
│       ├── Prompter.php                   # Main prompt facade
│       ├── Questions/
│       │   ├── TextQuestion.php
│       │   ├── PasswordQuestion.php
│       │   ├── ConfirmQuestion.php
│       │   ├── ChoiceQuestion.php
│       │   └── MultiChoiceQuestion.php
│       ├── Progress/
│       │   ├── ProgressBar.php
│       │   ├── Spinner.php
│       │   └── SpinnerStyles.php
│       └── Formatters/
│           ├── TableFormatter.php
│           └── BoxFormatter.php
```

### Component Relationships

```
┌─────────────────────────────────────────────────────────────────┐
│                      Command Execution                           │
│                                                                 │
│  BaseCommand                                                    │
│      │                                                          │
│      ├── prompt()  ─────────────────┐                          │
│      ├── confirm() ─────────────────┤                          │
│      ├── choice()  ─────────────────┤                          │
│      └── progress()─────────────────┤                          │
│                                     │                          │
│                                     ▼                          │
│                            ┌─────────────────┐                 │
│                            │    Prompter     │                 │
│                            └─────────────────┘                 │
│                                     │                          │
│              ┌──────────────────────┼──────────────────────┐  │
│              ▼                      ▼                      ▼   │
│     ┌──────────────┐      ┌──────────────┐      ┌──────────┐  │
│     │ TextQuestion │      │ ChoiceQuestion│     │ Spinner  │  │
│     └──────────────┘      └──────────────┘      └──────────┘  │
│              │                      │                      │   │
│              └──────────────────────┼──────────────────────┘  │
│                                     ▼                          │
│                         ┌─────────────────────┐               │
│                         │ Symfony Console     │               │
│                         │ QuestionHelper      │               │
│                         └─────────────────────┘               │
└─────────────────────────────────────────────────────────────────┘
```

---

## Prompter System

### Prompter Class

```php
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
 */
class Prompter
{
    private QuestionHelper $helper;
    private InputInterface $input;
    private OutputInterface $output;

    /**
     * Create a new Prompter instance
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
     */
    public function isInteractive(): bool
    {
        return $this->input->isInteractive();
    }

    /**
     * Ask for text input
     *
     * @param string $question The question to ask
     * @param string|null $default Default value
     * @param callable|null $validator Validation callback
     * @return string|null The answer
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
        $question = new Question($prompt, $default);

        if ($validator !== null) {
            $question->setValidator($validator);
        }

        return $this->helper->ask($this->input, $this->output, $question);
    }

    /**
     * Ask for secret input (password)
     *
     * @param string $question The question to ask
     * @param callable|null $validator Validation callback
     * @return string|null The answer
     */
    public function secret(
        string $question,
        ?callable $validator = null
    ): ?string {
        if (!$this->isInteractive()) {
            return null;
        }

        $prompt = $this->formatQuestion($question);
        $question = new Question($prompt);
        $question->setHidden(true);
        $question->setHiddenFallback(false);

        if ($validator !== null) {
            $question->setValidator($validator);
        }

        return $this->helper->ask($this->input, $this->output, $question);
    }

    /**
     * Ask for confirmation (yes/no)
     *
     * @param string $question The question to ask
     * @param bool $default Default value
     * @return bool The answer
     */
    public function confirm(string $question, bool $default = true): bool
    {
        if (!$this->isInteractive()) {
            return $default;
        }

        $defaultStr = $default ? 'yes' : 'no';
        $prompt = $this->formatQuestion($question, $defaultStr);

        $question = new ConfirmationQuestion($prompt, $default);

        return (bool) $this->helper->ask($this->input, $this->output, $question);
    }

    /**
     * Ask to choose from options
     *
     * @param string $question The question to ask
     * @param array<string|int, string> $choices Available choices
     * @param string|int|null $default Default choice
     * @return string|int The selected choice
     */
    public function choice(
        string $question,
        array $choices,
        string|int|null $default = null
    ): string|int {
        if (!$this->isInteractive()) {
            return $default ?? array_key_first($choices);
        }

        $prompt = $this->formatQuestion($question, (string) $default);
        $question = new ChoiceQuestion($prompt, $choices, $default);
        $question->setErrorMessage('Choice "%s" is invalid.');

        return $this->helper->ask($this->input, $this->output, $question);
    }

    /**
     * Ask to choose multiple options
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

        $question = new ChoiceQuestion($prompt, $choices, $defaultStr);
        $question->setMultiselect(true);
        $question->setErrorMessage('Choice "%s" is invalid.');

        return (array) $this->helper->ask($this->input, $this->output, $question);
    }

    /**
     * Ask with auto-completion
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
        $question = new Question($prompt, $default);
        $question->setAutocompleterValues($suggestions);

        return $this->helper->ask($this->input, $this->output, $question);
    }

    /**
     * Display an info message
     */
    public function info(string $message): void
    {
        $this->output->writeln("<info>{$message}</info>");
    }

    /**
     * Display a warning message
     */
    public function warn(string $message): void
    {
        $this->output->writeln("<comment>{$message}</comment>");
    }

    /**
     * Display an error message
     */
    public function error(string $message): void
    {
        $this->output->writeln("<error>{$message}</error>");
    }

    /**
     * Display a success message
     */
    public function success(string $message): void
    {
        $this->output->writeln("<info>✓</info> {$message}");
    }

    /**
     * Add a newline
     */
    public function newLine(int $count = 1): void
    {
        $this->output->write(str_repeat(PHP_EOL, $count));
    }

    /**
     * Format question with default value hint
     */
    private function formatQuestion(string $question, ?string $default = null): string
    {
        $suffix = $default !== null ? " [<comment>{$default}</comment>]" : '';
        return " <info>?</info> {$question}{$suffix}: ";
    }
}
```

---

## Question Types

### Interactive Scaffold Example

```php
<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Scaffold;

use Glueful\Console\BaseCommand;
use Glueful\Console\Interactive\Prompter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'scaffold:model',
    description: 'Scaffold an ORM model class (interactive mode supported)'
)]
class ModelCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'The model name')
            ->addOption('migration', 'm', InputOption::VALUE_NONE, 'Create migration')
            ->addOption('factory', 'f', InputOption::VALUE_NONE, 'Create factory')
            ->addOption('resource', 'r', InputOption::VALUE_NONE, 'Create resource controller')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Create migration, factory, and resource')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $prompter = $this->getPrompter($input, $output);

        // Get model name (from argument or interactive prompt)
        $name = $input->getArgument('name');

        if ($name === null) {
            $name = $prompter->ask(
                'What should the model be named?',
                null,
                fn($answer) => $this->validateModelName($answer)
            );

            if ($name === null) {
                $prompter->error('Model name is required.');
                return 1;
            }
        }

        // Interactive options if not specified via flags
        $migration = $input->getOption('migration') || $input->getOption('all');
        $factory = $input->getOption('factory') || $input->getOption('all');
        $resource = $input->getOption('resource') || $input->getOption('all');

        if (!$input->getOption('all') && $prompter->isInteractive()) {
            if (!$migration) {
                $migration = $prompter->confirm(
                    'Would you like to create a migration?',
                    true
                );
            }

            if (!$factory) {
                $factory = $prompter->confirm(
                    'Would you like to create a factory?',
                    true
                );
            }

            if (!$resource) {
                $resource = $prompter->confirm(
                    'Would you like to create a resource controller?',
                    false
                );
            }
        }

        // Display summary
        $prompter->newLine();
        $prompter->info("Creating model: app/Models/{$name}.php");

        if ($migration) {
            $prompter->info("Creating migration: database/migrations/..._create_" .
                $this->toTableName($name) . "_table.php");
        }

        if ($factory) {
            $prompter->info("Creating factory: database/factories/{$name}Factory.php");
        }

        if ($resource) {
            $prompter->info("Creating controller: app/Http/Controllers/{$name}Controller.php");
        }

        $prompter->newLine();

        // Execute generation
        $this->generateModel($name, $migration, $factory, $resource);

        $prompter->success('All done!');

        return 0;
    }

    private function validateModelName(?string $name): string
    {
        if ($name === null || trim($name) === '') {
            throw new \InvalidArgumentException('Model name cannot be empty.');
        }

        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name)) {
            throw new \InvalidArgumentException(
                'Model name must start with uppercase letter and contain only alphanumeric characters.'
            );
        }

        return $name;
    }

    private function toTableName(string $name): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name)) . 's';
    }

    private function generateModel(
        string $name,
        bool $migration,
        bool $factory,
        bool $resource
    ): void {
        // Implementation...
    }
}
```

---

## Progress Indicators

### Progress Bar

```php
<?php

declare(strict_types=1);

namespace Glueful\Console\Interactive\Progress;

use Symfony\Component\Console\Helper\ProgressBar as SymfonyProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Enhanced Progress Bar wrapper
 */
class ProgressBar
{
    private SymfonyProgressBar $bar;
    private OutputInterface $output;

    /**
     * Create a new progress bar
     */
    public function __construct(OutputInterface $output, int $max = 0)
    {
        $this->output = $output;
        $this->bar = new SymfonyProgressBar($output, $max);

        // Configure default format
        $this->bar->setFormat(
            " %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%"
        );
    }

    /**
     * Set the maximum number of steps
     */
    public function setMaxSteps(int $max): self
    {
        $this->bar->setMaxSteps($max);
        return $this;
    }

    /**
     * Set a custom format
     */
    public function setFormat(string $format): self
    {
        $this->bar->setFormat($format);
        return $this;
    }

    /**
     * Start the progress bar
     */
    public function start(?int $max = null): self
    {
        $this->bar->start($max);
        return $this;
    }

    /**
     * Advance by one step
     */
    public function advance(int $step = 1): self
    {
        $this->bar->advance($step);
        return $this;
    }

    /**
     * Set the current progress
     */
    public function setProgress(int $step): self
    {
        $this->bar->setProgress($step);
        return $this;
    }

    /**
     * Set a message to display
     */
    public function setMessage(string $message, string $name = 'message'): self
    {
        $this->bar->setMessage($message, $name);
        return $this;
    }

    /**
     * Finish the progress bar
     */
    public function finish(): self
    {
        $this->bar->finish();
        $this->output->writeln('');
        return $this;
    }

    /**
     * Create a progress bar for iterating over items
     *
     * @template T
     * @param iterable<T> $items
     * @return \Generator<T>
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
}
```

### Spinner

```php
<?php

declare(strict_types=1);

namespace Glueful\Console\Interactive\Progress;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Spinner animation for indeterminate progress
 */
class Spinner
{
    private OutputInterface $output;
    private string $message;
    private int $frame = 0;
    private bool $running = false;

    /**
     * Available spinner styles
     */
    public const STYLES = [
        'dots' => ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'],
        'line' => ['|', '/', '-', '\\'],
        'arrows' => ['←', '↖', '↑', '↗', '→', '↘', '↓', '↙'],
        'bouncing' => ['⠁', '⠂', '⠄', '⡀', '⢀', '⠠', '⠐', '⠈'],
    ];

    /**
     * @var array<string>
     */
    private array $frames;

    /**
     * Create a new spinner
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
     */
    public function setMessage(string $message): self
    {
        $this->message = $message;
        return $this;
    }

    /**
     * Render the current frame
     */
    public function render(): void
    {
        $frame = $this->frames[$this->frame % count($this->frames)];
        $this->frame++;

        // Clear line and write new frame
        $this->output->write("\r\033[K");
        $this->output->write(" <info>{$frame}</info> {$this->message}");
    }

    /**
     * Run a callback with spinner animation
     *
     * @template T
     * @param callable(): T $callback
     * @return T
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
     * Start the spinner (manual control)
     */
    public function start(): void
    {
        $this->running = true;
        $this->render();
    }

    /**
     * Tick the spinner (advance one frame)
     */
    public function tick(): void
    {
        if ($this->running) {
            $this->render();
        }
    }

    /**
     * Stop the spinner
     */
    public function stop(): void
    {
        $this->running = false;
        $this->output->write("\r\033[K");
    }

    /**
     * Stop with success message
     */
    public function success(string $message): void
    {
        $this->stop();
        $this->output->writeln(" <info>✓</info> {$message}");
    }

    /**
     * Stop with error message
     */
    public function error(string $message): void
    {
        $this->stop();
        $this->output->writeln(" <error>✗</error> {$message}");
    }
}
```

---

## Command Integration

### BaseCommand Enhancements

```php
<?php

declare(strict_types=1);

namespace Glueful\Console;

use Glueful\Console\Interactive\Prompter;
use Glueful\Console\Interactive\Progress\ProgressBar;
use Glueful\Console\Interactive\Progress\Spinner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base command with interactive helpers
 */
abstract class BaseCommand extends Command
{
    protected ?Prompter $prompter = null;
    protected ?InputInterface $input = null;
    protected ?OutputInterface $output = null;

    /**
     * Get the prompter instance
     */
    protected function getPrompter(
        InputInterface $input,
        OutputInterface $output
    ): Prompter {
        if ($this->prompter === null) {
            $this->prompter = new Prompter(
                $this->getHelper('question'),
                $input,
                $output
            );
        }

        return $this->prompter;
    }

    /**
     * Shortcut: Ask for text input
     */
    protected function ask(string $question, ?string $default = null): ?string
    {
        return $this->getPrompter($this->input, $this->output)
            ->ask($question, $default);
    }

    /**
     * Shortcut: Ask for confirmation
     */
    protected function confirm(string $question, bool $default = true): bool
    {
        return $this->getPrompter($this->input, $this->output)
            ->confirm($question, $default);
    }

    /**
     * Shortcut: Ask to choose from options
     */
    protected function choice(
        string $question,
        array $choices,
        string|int|null $default = null
    ): string|int {
        return $this->getPrompter($this->input, $this->output)
            ->choice($question, $choices, $default);
    }

    /**
     * Create a progress bar
     */
    protected function createProgressBar(int $max = 0): ProgressBar
    {
        return new ProgressBar($this->output, $max);
    }

    /**
     * Create a spinner
     */
    protected function createSpinner(
        string $message = 'Loading...',
        string $style = 'dots'
    ): Spinner {
        return new Spinner($this->output, $message, $style);
    }

    /**
     * Run a task with progress bar
     *
     * @template T
     * @param iterable<T> $items Items to process
     * @param callable(T): void $callback Processing callback
     * @param string $message Progress message
     */
    protected function withProgress(
        iterable $items,
        callable $callback,
        string $message = 'Processing...'
    ): void {
        $progress = $this->createProgressBar();
        $progress->setFormat(" {$message}\n %current%/%max% [%bar%] %percent:3s%%");

        foreach ($progress->iterate($items) as $item) {
            $callback($item);
        }
    }

    /**
     * Run a task with spinner
     *
     * @template T
     * @param callable(): T $callback
     * @param string $message Spinner message
     * @return T
     */
    protected function withSpinner(callable $callback, string $message = 'Loading...'): mixed
    {
        $spinner = $this->createSpinner($message);
        return $spinner->run($callback);
    }

    /**
     * Confirm before destructive operation
     */
    protected function confirmDestructive(string $message): bool
    {
        if (!$this->input->isInteractive()) {
            return $this->input->getOption('force') ?? false;
        }

        $this->output->writeln('');
        $this->output->writeln("<comment>⚠️  Warning: This is a destructive operation.</comment>");

        return $this->confirm($message, false);
    }
}
```

---

## Implementation Phases

### Phase 1: Core Prompter (Week 1)

**Deliverables:**
- [ ] `Prompter` class with all question types
- [ ] Integration into `BaseCommand`
- [ ] `--no-interaction` support
- [ ] Basic styling

**Acceptance Criteria:**
```bash
$ php glueful scaffold:model

 ? What should the model be named?: User
 ? Would you like to create a migration? (yes/no) [yes]: yes
 ? Would you like to create a factory? (yes/no) [yes]: yes
 ? Would you like to create a resource controller? (yes/no) [no]: no

Creating model: app/Models/User.php
Creating migration: database/migrations/..._create_users_table.php
Creating factory: database/factories/UserFactory.php

✓ All done!
```

### Phase 2: Progress Indicators (Week 1-2)

**Deliverables:**
- [ ] `ProgressBar` wrapper
- [ ] `Spinner` class with styles
- [ ] Helper methods in `BaseCommand`
- [ ] Integration with existing commands

**Acceptance Criteria:**
```bash
$ php glueful db:seed

 Seeding database...
 23/50 [============>---------------]  46% 0:12/0:26

$ php glueful cache:clear

 ⠹ Clearing cache...
 ✓ Cache cleared successfully.
```

### Phase 3: Command Integration (Week 2)

**Deliverables:**
- [ ] Update `scaffold:model` for interactive mode
- [ ] Update `scaffold:controller` for interactive mode
- [ ] Update destructive commands with confirmation
- [ ] Documentation

**Acceptance Criteria:**
```bash
# Interactive mode
$ php glueful scaffold:model
 ? What should the model be named?: Post
 ...

# Non-interactive mode (CI/CD)
$ php glueful scaffold:model Post --migration --factory --no-interaction
Creating model: app/Models/Post.php
...
```

---

## Testing Strategy

### Unit Tests

```php
<?php

namespace Glueful\Tests\Unit\Console\Interactive;

use PHPUnit\Framework\TestCase;
use Glueful\Console\Interactive\Prompter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Helper\QuestionHelper;

class PrompterTest extends TestCase
{
    public function testReturnsDefaultInNonInteractiveMode(): void
    {
        $input = new ArrayInput([]);
        $input->setInteractive(false);

        $output = new BufferedOutput();
        $helper = new QuestionHelper();

        $prompter = new Prompter($helper, $input, $output);

        $result = $prompter->ask('Name?', 'default');

        $this->assertEquals('default', $result);
    }

    public function testConfirmReturnsFalseByDefault(): void
    {
        // Test confirm() with default=false
    }
}
```

---

## API Reference

### Prompter Methods

| Method | Description | Returns |
|--------|-------------|---------|
| `ask($question, $default, $validator)` | Ask for text input | `string\|null` |
| `secret($question, $validator)` | Ask for hidden input | `string\|null` |
| `confirm($question, $default)` | Yes/no confirmation | `bool` |
| `choice($question, $choices, $default)` | Single choice | `string\|int` |
| `multiChoice($question, $choices, $defaults)` | Multiple choice | `array` |
| `suggest($question, $suggestions, $default)` | With autocomplete | `string\|null` |
| `info($message)` | Display info message | `void` |
| `warn($message)` | Display warning | `void` |
| `error($message)` | Display error | `void` |
| `success($message)` | Display success | `void` |
| `newLine($count)` | Add newlines | `void` |
| `isInteractive()` | Check interactive mode | `bool` |

### BaseCommand Helpers

| Method | Description |
|--------|-------------|
| `ask()` | Shortcut for prompter ask |
| `confirm()` | Shortcut for confirmation |
| `choice()` | Shortcut for choice menu |
| `createProgressBar()` | Create progress bar |
| `createSpinner()` | Create spinner |
| `withProgress()` | Run with progress bar |
| `withSpinner()` | Run with spinner |
| `confirmDestructive()` | Confirm destructive operation |

### Progress Bar Methods

| Method | Description |
|--------|-------------|
| `start($max)` | Start progress |
| `advance($step)` | Advance by steps |
| `setProgress($step)` | Set absolute progress |
| `setMessage($msg)` | Set display message |
| `finish()` | Complete progress |
| `iterate($items)` | Generator for iteration |

### Spinner Methods

| Method | Description |
|--------|-------------|
| `start()` | Start animation |
| `tick()` | Advance frame |
| `stop()` | Stop animation |
| `run($callback)` | Run callback with spinner |
| `success($msg)` | Stop with success |
| `error($msg)` | Stop with error |
| `setMessage($msg)` | Update message |
