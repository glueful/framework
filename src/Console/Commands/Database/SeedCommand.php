<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Database;

use Glueful\Console\BaseCommand;
use Glueful\Database\Seeders\Seeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Database Seed Command
 *
 * Seeds the database with records using seeder classes.
 *
 * @package Glueful\Console\Commands\Database
 */
#[AsCommand(
    name: 'db:seed',
    description: 'Seed the database with records'
)]
class SeedCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setDescription('Seed the database with records')
            ->setHelp($this->getDetailedHelp())
            ->addArgument(
                'class',
                InputArgument::OPTIONAL,
                'The seeder class to run',
                null
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force the operation to run in production'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Production check
        $force = (bool) $input->getOption('force');
        if ($this->isProduction() && !$force) {
            $this->error('Cannot run seeder in production without --force option.');
            $this->line('');
            $this->line('If you really want to seed in production, run:');
            $this->line('  php glueful db:seed --force');
            return self::FAILURE;
        }

        /** @var string|null $seederClass */
        $seederClass = $input->getArgument('class');

        // Resolve the seeder class
        $seederClass = $this->resolveSeederClass($seederClass);

        if ($seederClass === null) {
            $this->error('No seeder class found.');
            $this->line('');
            $this->line('Create a seeder with:');
            $this->line('  php glueful scaffold:seeder DatabaseSeeder');
            return self::FAILURE;
        }

        if (!class_exists($seederClass)) {
            $this->error("Seeder class not found: {$seederClass}");
            $this->line('');
            $this->line('Available seeders should be in the database/seeders/ directory.');
            return self::FAILURE;
        }

        // Validate the seeder class
        if (!is_subclass_of($seederClass, Seeder::class)) {
            $this->error("Class {$seederClass} must extend " . Seeder::class);
            return self::FAILURE;
        }

        $this->info('Seeding database...');
        $this->line('');

        try {
            $startTime = microtime(true);

            /** @var Seeder $seeder */
            $seeder = $this->container->get($seederClass);

            // Run dependencies first
            $this->runDependencies($seeder, []);

            // Run the main seeder
            $this->runSeeder($seederClass);

            $elapsed = round((microtime(true) - $startTime) * 1000, 2);

            $this->line('');
            $this->success("Database seeding completed in {$elapsed}ms.");
        } catch (\Throwable $e) {
            $this->error('Seeding failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the seeder class name
     */
    private function resolveSeederClass(?string $class): ?string
    {
        if ($class !== null) {
            // If it doesn't have a namespace, assume it's in Database\Seeders
            if (!str_contains($class, '\\')) {
                $class = 'Database\\Seeders\\' . $class;
            }
            return $class;
        }

        // Try to find DatabaseSeeder
        $candidates = [
            'Database\\Seeders\\DatabaseSeeder',
            'App\\Database\\Seeders\\DatabaseSeeder',
        ];

        foreach ($candidates as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Run seeder dependencies recursively
     *
     * @param Seeder $seeder The seeder to get dependencies from
     * @param array<string> $ran Already ran seeders to prevent cycles
     */
    private function runDependencies(Seeder $seeder, array $ran): void
    {
        $dependencies = $seeder->getDependencies();

        foreach ($dependencies as $dependencyClass) {
            if (in_array($dependencyClass, $ran, true)) {
                continue; // Already ran
            }

            if (!class_exists($dependencyClass)) {
                $this->warning("Dependency not found: {$dependencyClass}");
                continue;
            }

            /** @var Seeder $dependency */
            $dependency = $this->container->get($dependencyClass);

            // Run the dependency's dependencies first
            $this->runDependencies($dependency, $ran);

            // Run the dependency
            $this->runSeeder($dependencyClass);
            $ran[] = $dependencyClass;
        }
    }

    /**
     * Run a single seeder
     *
     * @param class-string<Seeder> $seederClass
     */
    private function runSeeder(string $seederClass): void
    {
        $shortName = class_basename($seederClass);
        $this->line("  Running {$shortName}...");

        /** @var Seeder $seeder */
        $seeder = $this->container->get($seederClass);
        $seeder->run();

        $this->line("  <fg=green>✓</> {$shortName}");
    }


    /**
     * Get detailed help text
     */
    private function getDetailedHelp(): string
    {
        return <<<HELP
Seed the database with records using seeder classes.

Seeders are useful for:
- Populating initial data (roles, permissions, settings)
- Creating test data for development
- Setting up demo environments

Examples:
  php glueful db:seed                           # Run DatabaseSeeder
  php glueful db:seed UserSeeder                # Run specific seeder
  php glueful db:seed Database\\Seeders\\RoleSeeder  # Full class name
  php glueful db:seed --force                   # Run in production

Creating Seeders:
  php glueful scaffold:seeder UserSeeder
  php glueful scaffold:seeder DatabaseSeeder

Seeder Structure:
  database/seeders/
  ├── DatabaseSeeder.php   # Main seeder (calls others)
  ├── RoleSeeder.php       # Seeds roles
  └── UserSeeder.php       # Seeds users

Production Safety:
  The --force option is required to run seeders in production.
  This prevents accidental data modifications in live environments.
HELP;
    }
}

/**
 * Get the class basename (helper function)
 */
if (!function_exists('class_basename')) {
    function class_basename(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts);
    }
}
