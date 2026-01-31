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
 * Scaffold Seeder Command
 *
 * Generates a new database seeder class.
 *
 * @package Glueful\Console\Commands\Scaffold
 */
#[AsCommand(
    name: 'scaffold:seeder',
    description: 'Scaffold a new database seeder class'
)]
class SeederCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setDescription('Scaffold a new database seeder class')
            ->setHelp($this->getDetailedHelp())
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The name of the seeder class (e.g., UserSeeder)'
            )
            ->addOption(
                'model',
                'm',
                InputOption::VALUE_OPTIONAL,
                'The model class to use for seeding',
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
                'Custom path for the seeder file',
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

        // Normalize the name
        $name = $this->normalizeSeederName($name);

        // Validate the name
        if (!$this->isValidClassName($name)) {
            $this->error("Invalid seeder name: {$name}");
            $this->line('Class names must be PascalCase and contain only letters and numbers.');
            return self::FAILURE;
        }

        // Determine the file path
        $basePath = $customPath ?? $this->getDefaultSeederPath();
        $filePath = $this->buildFilePath($basePath, $name);

        // Check if file exists
        if (file_exists($filePath) && !$force) {
            $this->error("Seeder already exists: {$filePath}");
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
        $content = $this->generateSeederClass($name, $model);

        // Write the file
        if (file_put_contents($filePath, $content) === false) {
            $this->error("Failed to write file: {$filePath}");
            return self::FAILURE;
        }

        $this->success("Seeder scaffolded successfully!");
        $this->line("File: {$filePath}");
        $this->line('');
        $this->info('Next steps:');
        $this->line('1. Implement your seeding logic in the run() method');
        $this->line('2. Add any dependencies to the $dependencies array');
        $this->line('3. Run the seeder with: php glueful db:seed ' . $this->extractClassName($name));
        $this->line('');

        if ($name === 'DatabaseSeeder') {
            $this->line('Tip: DatabaseSeeder is the main seeder that can call other seeders:');
            $this->line('  $this->call([UserSeeder::class, RoleSeeder::class]);');
        }

        return self::SUCCESS;
    }

    /**
     * Normalize the seeder name
     */
    private function normalizeSeederName(string $name): string
    {
        // Remove .php extension if provided
        $name = preg_replace('/\.php$/', '', $name) ?? $name;

        // Ensure PascalCase
        $name = ucfirst($name);

        // Add Seeder suffix if not present
        if (!str_ends_with($name, 'Seeder')) {
            $name .= 'Seeder';
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
     * Get the default path for seeder files
     */
    private function getDefaultSeederPath(): string
    {
        return base_path($this->getContext(), 'database/seeders');
    }

    /**
     * Build the full file path from the base path and name
     */
    private function buildFilePath(string $basePath, string $name): string
    {
        return rtrim($basePath, '/') . '/' . $name . '.php';
    }

    /**
     * Extract class name from name
     */
    private function extractClassName(string $name): string
    {
        return $name;
    }

    /**
     * Generate the seeder class content
     */
    private function generateSeederClass(string $name, ?string $model): string
    {
        $className = $this->extractClassName($name);

        // Infer model from seeder name if not provided
        if ($model === null && $name !== 'DatabaseSeeder') {
            $potentialModel = str_replace('Seeder', '', $name);
            if ($potentialModel !== '' && $potentialModel !== $name) {
                $model = $potentialModel;
            }
        }

        // Check if this is DatabaseSeeder (main orchestrator)
        if ($className === 'DatabaseSeeder') {
            return $this->generateDatabaseSeeder();
        }

        $modelImport = '';
        $modelUsage = '';

        if ($model !== null) {
            $modelClass = str_contains($model, '\\') ? $model : "App\\Models\\{$model}";
            $modelShort = class_basename_str($model);
            $modelImport = "use {$modelClass};\n";
            $modelUsage = $this->generateModelUsage($modelShort);
        }

        return <<<PHP
<?php

declare(strict_types=1);

namespace Database\Seeders;

use Glueful\Database\Seeders\Seeder;
{$modelImport}
/**
 * {$className}
 *
 * Database seeder for populating initial data.
 *
 * @package Database\Seeders
 */
class {$className} extends Seeder
{
    /**
     * Seeders that must run before this one
     *
     * @var array<class-string<Seeder>>
     */
    protected array \$dependencies = [
        // RoleSeeder::class,
    ];

    /**
     * Run the database seeds
     */
    public function run(): void
    {
{$modelUsage}    }
}

PHP;
    }

    /**
     * Generate the main DatabaseSeeder class
     */
    private function generateDatabaseSeeder(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace Database\Seeders;

use Glueful\Database\Seeders\Seeder;

/**
 * DatabaseSeeder
 *
 * Main database seeder that orchestrates other seeders.
 *
 * @package Database\Seeders
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds
     *
     * Call other seeders in the order they should run.
     */
    public function run(): void
    {
        $this->call([
            // Add your seeders here in the order they should run
            // RoleSeeder::class,
            // UserSeeder::class,
            // PostSeeder::class,
        ]);
    }
}

PHP;
    }

    /**
     * Generate model usage example
     */
    private function generateModelUsage(string $modelName): string
    {
        return <<<PHP
        // Create records using the model
        // {$modelName}::create(\$this->getContext(), [
        //     'name' => 'Example',
        // ]);

        // Or use factory if available
        // {$modelName}::factory()->count(10)->create();

PHP;
    }

    /**
     * Get detailed help text
     */
    private function getDetailedHelp(): string
    {
        return <<<HELP
Scaffold a new database seeder class.

Seeders are used to populate your database with initial or test data.
They can be run individually or orchestrated through DatabaseSeeder.

Examples:
  php glueful scaffold:seeder UserSeeder
  php glueful scaffold:seeder RoleSeeder --model=Role
  php glueful scaffold:seeder DatabaseSeeder

The generated class will be placed in database/seeders/.

Seeder Organization:
  DatabaseSeeder should be your main entry point, calling other seeders:

  class DatabaseSeeder extends Seeder
  {
      public function run(): void
      {
          \$this->call([
              RoleSeeder::class,
              UserSeeder::class,
          ]);
      }
  }

Dependencies:
  Use the \$dependencies property to ensure seeders run in order:

  class UserSeeder extends Seeder
  {
      protected array \$dependencies = [RoleSeeder::class];
  }

Running Seeders:
  php glueful db:seed                  # Run DatabaseSeeder
  php glueful db:seed UserSeeder       # Run specific seeder
  php glueful db:seed --force          # Run in production
HELP;
    }
}

/**
 * Get the class basename from a string
 */
function class_basename_str(string $class): string
{
    $parts = explode('\\', $class);
    return end($parts);
}
