<?php

namespace Glueful\Console\Commands;

use Glueful\Console\BaseCommand;
use Glueful\Installer\DatabaseConfig;
use Glueful\Installer\Installer;
use Glueful\Installer\InstallOptions;
use Glueful\Installer\InstallStep;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Installation and Setup Command
 *
 * Thin wrapper over {@see \Glueful\Installer\Installer}: it gathers input
 * (interactive credential prompts, or environment variables in --quiet mode),
 * builds an {@see InstallOptions}, runs the engine-agnostic installer, and
 * renders the resulting {@see \Glueful\Installer\InstallResult} steps.
 *
 * @package Glueful\Console\Commands
 */
#[AsCommand(
    name: 'install',
    description: 'Run installation setup wizard for new Glueful installation'
)]
class InstallCommand extends BaseCommand
{
    protected ContainerInterface $installContainer;

    public function __construct()
    {
        parent::__construct();
        $this->installContainer = container($this->getContext());
    }

    protected function configure(): void
    {
        $this->setDescription('Run installation setup wizard for new Glueful installation')
             ->setHelp($this->getDetailedHelp())
             ->addOption(
                 'skip-database',
                 null,
                 InputOption::VALUE_NONE,
                 'Skip database setup and migrations'
             )
             ->addOption(
                 'skip-db',
                 null,
                 InputOption::VALUE_NONE,
                 'Skip database setup and migrations (alias for --skip-database)'
             )
             ->addOption(
                 'skip-keys',
                 null,
                 InputOption::VALUE_NONE,
                 'Skip security key generation'
             )
             ->addOption(
                 'skip-cache',
                 null,
                 InputOption::VALUE_NONE,
                 'Skip cache initialization'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Overwrite existing configurations without confirmation'
             )
             ->addOption(
                 'quiet',
                 'q',
                 InputOption::VALUE_NONE,
                 'Non-interactive mode using environment variables'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $skipDatabase = (bool) $input->getOption('skip-database') || (bool) $input->getOption('skip-db');
        $skipKeys = (bool) $input->getOption('skip-keys');
        $skipCache = (bool) $input->getOption('skip-cache');
        $force = (bool) $input->getOption('force');
        $quiet = (bool) $input->getOption('quiet');

        try {
            if (!$quiet) {
                $this->showWelcomeMessage();
            } else {
                $this->showQuietModeConfirmation();
            }

            // Gather database credentials interactively; in --quiet mode leave
            // database = null so the installer uses the existing .env values.
            $database = null;
            if (!$skipDatabase && !$quiet) {
                $engine = $this->choice('Which database engine?', ['mysql', 'pgsql', 'sqlite'], 'sqlite');
                $database = $this->promptDatabaseConfig($engine);
            }

            $installer = new Installer(base_path($this->getContext()), $this->getContext());
            $result = $installer->run(new InstallOptions(
                database: $database,
                skipDatabase: $skipDatabase,
                skipKeys: $skipKeys,
                skipCache: $skipCache,
                force: $force,
            ));

            foreach ($result->steps as $step) {
                $glyph = $step->status === InstallStep::OK ? '✓'
                    : ($step->status === InstallStep::FAILED ? '✗' : '•');
                $this->line("{$glyph} {$step->name}: {$step->message}");
            }

            if (!$result->ok) {
                $this->error('Installation failed. No partial state was written on a failed DB preflight.');
                $this->displayTroubleshootingInfo();
                return self::FAILURE;
            }

            $this->success('🎉 Installation complete.');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Installation failed: ' . $e->getMessage());
            $this->displayTroubleshootingInfo();
            return self::FAILURE;
        }
    }

    /**
     * Build a DatabaseConfig from interactive prompts for the chosen engine.
     */
    private function promptDatabaseConfig(string $engine): DatabaseConfig
    {
        if ($engine === 'sqlite') {
            $default = base_path($this->getContext(), 'storage/database/glueful.sqlite');
            return new DatabaseConfig('sqlite', database: $this->ask('SQLite file path', $default));
        }

        $port = $engine === 'pgsql' ? '5432' : '3306';
        $user = $engine === 'pgsql' ? 'postgres' : 'root';

        return new DatabaseConfig(
            engine: $engine,
            host: $this->ask('Database host', '127.0.0.1'),
            port: (int) $this->ask('Database port', $port),
            database: $this->ask('Database name', 'glueful'),
            username: $this->ask('Database username', $user),
            password: $this->secret('Database password'),
            schema: $engine === 'pgsql' ? ($this->ask('Schema', 'public') ?: null) : null,
            sslMode: $engine === 'pgsql' ? ($this->ask('SSL mode (blank for default)', '') ?: null) : null,
        );
    }

    private function showWelcomeMessage(): void
    {
        $this->line('');
        $this->line('╔══════════════════════════════════════════════╗');
        $this->line('║           Glueful Installation Wizard       ║');
        $this->line('╚══════════════════════════════════════════════╝');
        $this->line('');
        $this->info('Welcome! This wizard will help you set up your new Glueful installation.');
        $this->line('');
    }

    private function showQuietModeConfirmation(): void
    {
        $this->line('');
        $this->info('Running in quiet mode - using environment variables for configuration');
        $this->line('');
        $this->line('Required environment variables:');
        $this->line('• Database: DB_DRIVER, DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD');
        $this->line('');
        $this->line('• Security: TOKEN_SALT, JWT_KEY (generated if missing)');
        $this->line('');

        // In quiet/non-interactive/force modes, skip confirmation to support CI and scripted installs
        $isQuiet = (bool) ($this->input->getOption('quiet') ?? false);
        $isForced = (bool) ($this->input->getOption('force') ?? false);
        $isNonInteractive = !$this->input->isInteractive();
        if ($isQuiet || $isForced || $isNonInteractive) {
            return; // assume caller ensured env is set or wants generation where applicable
        }

        if (!$this->confirm('Have you set all required environment variables?', true)) {
            throw new \Exception('Installation cancelled. Please set required environment variables and try again.');
        }
    }

    private function getDetailedHelp(): string
    {
        return <<<HELP
Glueful Installation Setup Wizard

This command sets up a new Glueful installation with all required components:

Steps performed:
  1. Database connection preflight (no .env mutation on failure)
  2. Environment file preparation (.env from .env.example)
  3. Security key generation (APP_KEY, TOKEN_SALT, JWT_KEY)
  4. Database credentials written and migrations applied
  5. Cache system initialization

Examples:
  glueful install                           # Full interactive setup
  glueful install --force --quiet           # Force reinstall using environment variables
  glueful install --skip-database           # Skip database setup
  glueful install --skip-db                 # Skip database setup (alias)
  glueful install --skip-cache              # Skip cache initialization

Options allow you to customize which steps are performed during installation.
HELP;
    }

    private function displayTroubleshootingInfo(): void
    {
        $this->line('');
        $this->error('Installation failed. Troubleshooting information:');
        $this->line('');

        $this->line('Common issues:');
        $this->line('• Check PHP version (8.3+ required): php -v');
        $this->line('• Check required extensions: php -m');
        $this->line('• Verify database connection settings in .env');
        $this->line('• Ensure storage/ and database/ directories are writable');
        $this->line('• Check logs in storage/logs/ for detailed errors');
        $this->line('');

        $this->line('For help:');
        $this->line('• Documentation: https://glueful.com/docs/getting-started');
        $this->line('• GitHub Issues: https://github.com/glueful/glueful/issues');
    }
}
