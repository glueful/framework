<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Scaffold;

use Glueful\Console\BaseCommand;
use Glueful\Database\Factory\FakerBridge;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scaffold Factory Command
 *
 * Generates a new model factory class for test data generation.
 *
 * @package Glueful\Console\Commands\Scaffold
 */
#[AsCommand(
    name: 'scaffold:factory',
    description: 'Scaffold a new model factory class'
)]
class FactoryCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setDescription('Scaffold a new model factory class')
            ->setHelp($this->getDetailedHelp())
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The name of the factory class (e.g., UserFactory)'
            )
            ->addOption(
                'model',
                'm',
                InputOption::VALUE_OPTIONAL,
                'The model class the factory creates',
                null
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
                'Custom path for the factory file',
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

        /** @var string|null $modelOption */
        $modelOption = $input->getOption('model');
        $model = is_string($modelOption) ? $modelOption : null;

        // Check if Faker is available (dev dependency)
        $fakerAvailable = FakerBridge::isAvailable();
        if (!$fakerAvailable) {
            $this->warning('Note: fakerphp/faker is not installed.');
            $this->line('Install it with: composer require --dev fakerphp/faker');
            $this->line('');
        }

        // Normalize the name
        $name = $this->normalizeFactoryName($name);

        // Validate the name
        if (!$this->isValidClassName($name)) {
            $this->error("Invalid factory name: {$name}");
            $this->line('Class names must be PascalCase and contain only letters and numbers.');
            return self::FAILURE;
        }

        // Infer model from factory name if not provided
        if ($model === null) {
            $model = str_replace('Factory', '', $name);
        }

        // Determine the file path
        $basePath = $customPath ?? $this->getDefaultFactoryPath();
        $filePath = $this->buildFilePath($basePath, $name);

        // Check if file exists
        if (file_exists($filePath) && !$force) {
            $this->error("Factory already exists: {$filePath}");
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
        $content = $this->generateFactoryClass($name, $model);

        // Write the file
        if (file_put_contents($filePath, $content) === false) {
            $this->error("Failed to write file: {$filePath}");
            return self::FAILURE;
        }

        $this->success("Factory scaffolded successfully!");
        $this->line("File: {$filePath}");
        $this->line('');

        $this->table(['Property', 'Value'], [
            ['Factory', $name],
            ['Model', "App\\Models\\{$model}"],
            ['Faker', $fakerAvailable ? 'Available' : 'Not installed'],
        ]);

        $this->line('');
        $this->info('Next steps:');
        $this->line('1. Define your model attributes in the definition() method');
        $this->line('2. Add state methods for variations (admin(), unverified(), etc.)');
        $this->line("3. Add 'use HasFactory;' to your {$model} model");

        if (!$fakerAvailable) {
            $this->line('4. Install Faker: composer require --dev fakerphp/faker');
        }

        $this->line('');
        $this->line('Example usage:');
        $this->line("  \${$this->toVariableName($model)} = {$model}::factory()->create();");
        $this->line("  \${$this->toPluralVariableName($model)} = {$model}::factory()->count(10)->create();");

        return self::SUCCESS;
    }

    /**
     * Normalize the factory name
     */
    private function normalizeFactoryName(string $name): string
    {
        // Remove .php extension if provided
        $name = preg_replace('/\.php$/', '', $name) ?? $name;

        // Ensure PascalCase
        $name = ucfirst($name);

        // Add Factory suffix if not present
        if (!str_ends_with($name, 'Factory')) {
            $name .= 'Factory';
        }

        return $name;
    }

    /**
     * Validate the class name
     */
    private function isValidClassName(string $name): bool
    {
        return (bool) preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name);
    }

    /**
     * Get the default path for factory files
     */
    private function getDefaultFactoryPath(): string
    {
        return base_path('database/factories');
    }

    /**
     * Build the full file path from the base path and name
     */
    private function buildFilePath(string $basePath, string $name): string
    {
        return rtrim($basePath, '/') . '/' . $name . '.php';
    }

    /**
     * Convert model name to variable name (camelCase)
     */
    private function toVariableName(string $name): string
    {
        return lcfirst($name);
    }

    /**
     * Convert model name to plural variable name
     */
    private function toPluralVariableName(string $name): string
    {
        $var = lcfirst($name);
        // Simple pluralization
        $esSuffixes = str_ends_with($var, 's') || str_ends_with($var, 'x');
        $esSuffixes = $esSuffixes || str_ends_with($var, 'ch') || str_ends_with($var, 'sh');
        if ($esSuffixes) {
            return $var . 'es';
        }
        if (str_ends_with($var, 'y') && !in_array($var[-2] ?? '', ['a', 'e', 'i', 'o', 'u'], true)) {
            return substr($var, 0, -1) . 'ies';
        }
        return $var . 's';
    }

    /**
     * Generate the factory class content
     */
    private function generateFactoryClass(string $name, string $model): string
    {
        $className = $name;
        $modelClass = str_contains($model, '\\') ? $model : "App\\Models\\{$model}";
        $modelShort = $this->getShortClassName($model);

        return <<<PHP
<?php

declare(strict_types=1);

namespace Database\Factories;

use {$modelClass};
use Glueful\Database\Factory\Factory;

/**
 * Factory for {$modelShort} model
 *
 * Requires: composer require --dev fakerphp/faker
 *
 * @extends Factory<{$modelShort}>
 * @package Database\Factories
 */
class {$className} extends Factory
{
    /**
     * The model this factory creates
     *
     * @var class-string<{$modelShort}>
     */
    protected string \$model = {$modelShort}::class;

    /**
     * Define the model's default state
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Define default attributes here using \$this->faker
            // 'uuid' => \$this->faker->uuid(),
            // 'name' => \$this->faker->name(),
            // 'email' => \$this->faker->unique()->safeEmail(),
            // 'created_at' => \$this->faker->dateTimeBetween('-1 year'),
        ];
    }

    // Add state methods for model variations:

    // /**
    //  * Indicate that the model is an admin
    //  */
    // public function admin(): static
    // {
    //     return \$this->state([
    //         'role' => 'admin',
    //     ]);
    // }

    // /**
    //  * Indicate that the model is unverified
    //  */
    // public function unverified(): static
    // {
    //     return \$this->state([
    //         'email_verified_at' => null,
    //     ]);
    // }
}

PHP;
    }

    /**
     * Get the short class name from a fully qualified name
     */
    private function getShortClassName(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts);
    }

    /**
     * Get detailed help text
     */
    private function getDetailedHelp(): string
    {
        return <<<HELP
Scaffold a new model factory class for generating test data.

Factories provide a convenient way to generate test data for your models
using the Faker library for realistic fake data.

Examples:
  php glueful scaffold:factory UserFactory
  php glueful scaffold:factory PostFactory --model=Post
  php glueful scaffold:factory CommentFactory --force

The generated class will be placed in database/factories/.

Prerequisites:
  Faker must be installed as a dev dependency:
  composer require --dev fakerphp/faker

Model Integration:
  Add the HasFactory trait to your model:

  use Glueful\Database\ORM\Concerns\HasFactory;

  class User extends Model
  {
      use HasFactory;
  }

Usage in Tests:
  // Create a single model
  \$user = User::factory()->create();

  // Create multiple models
  \$users = User::factory()->count(10)->create();

  // Create with state
  \$admin = User::factory()->admin()->create();

  // Create with specific attributes
  \$user = User::factory()->create([
      'email' => 'test@example.com',
  ]);

  // Create without persisting (make)
  \$user = User::factory()->make();

Factory States:
  Define state methods for common variations:

  public function admin(): static
  {
      return \$this->state(['role' => 'admin']);
  }

  public function unverified(): static
  {
      return \$this->state(['email_verified_at' => null]);
  }
HELP;
    }
}
