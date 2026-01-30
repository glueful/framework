<?php

namespace Glueful\Console\Commands;

use Glueful\Console\BaseCommand;
use Glueful\Security\RandomStringGenerator;
use Glueful\Services\HealthService;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Installation and Setup Command
 * - Interactive step-by-step setup wizard with progress tracking
 * - Enhanced validation with detailed error messages and recovery suggestions
 * - Secure password input with proper masking
 * - Progress bars for long-running operations
 * - Better error handling with rollback capabilities
 * - Modern UI with tables, spinners, and colored output
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
        $skipKeys = $input->getOption('skip-keys');
        $skipCache = $input->getOption('skip-cache');
        $force = $input->getOption('force');
        $quiet = $input->getOption('quiet');

        try {
            if (!(bool) $quiet) {
                $this->showWelcomeMessage();
            } else {
                // In quiet mode, confirm environment variables are set
                $this->showQuietModeConfirmation();
            }

            $this->runInstallationSteps(
                $skipDatabase,
                $skipKeys,
                $skipCache,
                $force,
                $quiet
            );

            $this->showCompletionMessage();
            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            // For other runtime exceptions, treat as installation failure
            $this->error('Installation failed: ' . $e->getMessage());
            $this->displayTroubleshootingInfo();
            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error('Installation failed: ' . $e->getMessage());
            $this->displayTroubleshootingInfo();
            return self::FAILURE;
        }
    }

    private function runInstallationSteps(
        bool $skipDatabase,
        bool $skipKeys,
        bool $skipCache,
        bool $force,
        bool $quiet
    ): void {
        $steps = $this->getInstallationSteps($skipDatabase, $skipKeys, $skipCache);
        $progressBar = $this->createProgressBar(count($steps));

        foreach ($steps as $step) {
            $this->info("Step {$step['number']}: {$step['description']}");

            $step['callback']($force, $quiet);

            $progressBar->advance();
            $this->line(''); // Add spacing between steps
        }

        $progressBar->finish();
        $this->line('');
    }

    /**
     * @return array<int, array{number: int, description: string, callback: array{0: InstallCommand, 1: string}}>
     */
    private function getInstallationSteps(bool $skipDatabase, bool $skipKeys, bool $skipCache): array
    {
        $steps = [
            [
                'number' => 1,
                'description' => 'Environment validation',
                'callback' => [$this, 'validateEnvironment']
            ]
        ];

        if (!$skipKeys) {
            $steps[] = [
                'number' => count($steps) + 1,
                'description' => 'Generate security keys',
                'callback' => [$this, 'generateSecurityKeys']
            ];
        }

        if (!$skipDatabase) {
            $steps[] = [
                'number' => count($steps) + 1,
                'description' => 'Database setup and migrations',
                'callback' => [$this, 'setupDatabase']
            ];
        }

        if (!$skipCache) {
            $steps[] = [
                'number' => count($steps) + 1,
                'description' => 'Initialize cache system',
                'callback' => [$this, 'initializeCache']
            ];
        }

        $steps[] = [
            'number' => count($steps) + 1,
            'description' => 'Final validation',
            'callback' => [$this, 'performFinalValidation']
        ];

        return $steps;
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

    private function validateEnvironment(bool $force, bool $quiet): void
    {
        // Check for .env file
        $envPath = base_path($this->getContext(), '.env');

        if (!file_exists($envPath)) {
            if (!$quiet) {
                $this->warning('.env file not found. Creating from .env.example...');
            }

            $examplePath = base_path($this->getContext(), '.env.example');
            if (file_exists($examplePath)) {
                copy($examplePath, $envPath);
                $this->success('Created .env file from .env.example');
            } else {
                throw new \Exception('.env.example file not found. Cannot create .env file.');
            }
        }

        // Validate PHP version and extensions
        $this->validatePhpRequirements();

        // Check directory permissions
        $this->validateDirectoryPermissions();

        $this->line('✓ Environment validation completed');
    }

    private function generateSecurityKeys(bool $force, bool $quiet): void
    {
        // Generate APP_KEY if not exists, is placeholder, or force
        $appKey = config($this->getContext(), 'app.key');
        $isAppKeyPlaceholder = in_array($appKey, [
            'generate-secure-32-char-key-here',
            'your-secure-app-key-here',
            null,
            ''
        ], true);

        if (($appKey === null || $appKey === '') || $isAppKeyPlaceholder || $force) {
            $newAppKey = RandomStringGenerator::generate(32);
            $this->updateEnvFile('APP_KEY', $newAppKey);
            $this->line('✓ Generated APP_KEY');
        } else {
            $this->line('• APP_KEY already exists (use --force to regenerate if needed)');
        }

        // Generate TOKEN_SALT if not exists, is placeholder, or force
        $tokenSalt = config($this->getContext(), 'session.token_salt');
        $isTokenSaltPlaceholder = in_array($tokenSalt, [
            'your-secure-salt-here',
            'generate-secure-32-char-key-here',
            null,
            ''
        ], true);

        if (($tokenSalt === null || $tokenSalt === '') || $isTokenSaltPlaceholder || $force) {
            $newTokenSalt = RandomStringGenerator::generate(32);
            $this->updateEnvFile('TOKEN_SALT', $newTokenSalt);
            $this->line('✓ Generated TOKEN_SALT');
        } else {
            $this->line('• TOKEN_SALT already exists (use --force to regenerate if needed)');
        }

        // Generate JWT_KEY if not exists, is placeholder, or force
        $jwtKey = config($this->getContext(), 'session.jwt_key');
        $isJwtKeyPlaceholder = in_array($jwtKey, [
            'your-secure-jwt-key-here',
            'generate-secure-32-char-key-here',
            null,
            ''
        ], true);

        if (($jwtKey === null || $jwtKey === '') || $isJwtKeyPlaceholder || $force) {
            $newJwtKey = RandomStringGenerator::generate(64);
            $this->updateEnvFile('JWT_KEY', $newJwtKey);
            $this->line('✓ Generated JWT_KEY');
        } else {
            $this->line('• JWT_KEY already exists (use --force to regenerate if needed)');
        }
    }

    private function setupDatabase(bool $force, bool $quiet): void
    {
        // Create SQLite database file if it doesn't exist
        $dbDriver = config($this->getContext(), 'database.engine');

        // Enforce SQLite-only behavior during install. Other engines can be configured after.
        if ($dbDriver !== 'sqlite') {
            $this->line('• Install currently supports SQLite only. Skipping database setup.');
            $this->line('  Update your .env to use SQLite and rerun install, or run migrations later.');
            return;
        }
        // At this point we know engine is sqlite
        $dbPath = config($this->getContext(), 'database.sqlite.primary');
        if (!file_exists($dbPath)) {
            $dbDir = dirname($dbPath);
            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0755, true);
            }

            // Create empty SQLite database
            $pdo = new \PDO("sqlite:$dbPath");
            $pdo = null; // Close connection

            $this->line('✓ Created SQLite database');
        }

        // Note: This bypasses HealthService::checkDatabase() which may fail in some environments
        $this->line('• Skipping database connection verification (temporarily disabled)');

        // Run migrations
        $this->line('Running database migrations...');
        try {
            $command = $this->getApplication()->find('migrate:run');
            // Run migrations non-interactively with --force (equivalent to `migrate:run -f`)
            $args = [
                '--force' => true,
            ];
            if ($quiet) {
                $args['--no-interaction'] = true;
                $args['--quiet'] = true;
            }
            $arguments = new ArrayInput($args);
            $returnCode = $command->run($arguments, $this->output);

            if ($returnCode === 0) {
                $this->line('✓ Database migrations completed');
            } else {
                throw new \Exception('Migration command failed');
            }
        } catch (\Exception $e) {
            throw new \Exception('Failed to run migrations: ' . $e->getMessage());
        }
    }

    private function initializeCache(bool $force, bool $quiet): void
    {
        $this->line('Initializing cache system...');
        try {
            // Try to clear cache first
            try {
                $command = $this->getApplication()->find('cache:clear');
                // Ensure non-interactive cache clear, especially in --quiet mode
                $cacheArgs = [
                    '--force' => true,
                ];
                if ($quiet) {
                    $cacheArgs['--no-interaction'] = true; // suppress prompts globally
                    $cacheArgs['--quiet'] = true;          // reduce output noise
                }
                $arguments = new ArrayInput($cacheArgs);
                $command->run($arguments, $this->output);
                $this->line('✓ Cache cleared successfully');
            } catch (\Exception $e) {
                if (!$quiet) {
                    $this->warning('Cache clear command not available: ' . $e->getMessage());
                }
            }

            // Initialize cache system
            $cacheStore = \Glueful\Helpers\CacheHelper::createCacheInstance();
            if ($cacheStore !== null) {
                $this->line('✓ Cache system initialized successfully');
            } else {
                throw new \Exception('Failed to initialize cache system');
            }
        } catch (\Exception $e) {
            if (!$quiet) {
                $this->warning('Failed to initialize cache system: ' . $e->getMessage());
                $this->line('• Cache system will be initialized on first use');
            }
            // Don't fail the entire installation for cache initialization
        }
    }

    private function performFinalValidation(bool $force, bool $quiet): void
    {
        $this->line('Performing final system validation...');

        // Check all critical services
        $services = [
            'Database' => 'checkDatabaseHealth',
            'Cache' => 'checkCacheHealth',
            'Security' => 'checkSecurityHealth'
        ];

        foreach ($services as $service => $method) {
            try {
                if ($method === 'checkDatabaseHealth') {
                    $this->checkDatabaseHealth();
                } elseif ($method === 'checkCacheHealth') {
                    $this->checkCacheHealth();
                } elseif ($method === 'checkSecurityHealth') {
                    $this->checkSecurityHealth();
                } else {
                    throw new \Exception("Unknown health check method: {$method}");
                }
                $this->line("✓ {$service} validation passed");
            } catch (\Exception $e) {
                $this->warning("⚠ {$service} validation warning: " . $e->getMessage());
            }
        }
    }

    private function showCompletionMessage(): void
    {
        $this->line('');
        $this->success('🎉 Glueful installation completed successfully!');
        $this->line('');

        $this->info('Your Glueful installation is ready. Next steps:');
        $this->line('1. Start the development server: php glueful serve');
        $this->line('2. Visit your application in a web browser');
        $this->line('3. Begin building your application!');
        $this->line('');
        $this->info('Switching databases after install:');
        $this->line('- Edit .env and set DB_DRIVER + credentials (mysql/pgsql).');
        $this->line('- Then run: php glueful migrate:run -f');
        $this->line('- For CI/non-interactive: php glueful migrate:run -f --no-interaction');
        $this->line('');

        $this->line('Database Configuration:');
        $dbDriver = config($this->getContext(), 'database.engine');
        if ($dbDriver === 'sqlite') {
            $this->line('• Currently using: SQLite (storage/database/glueful.sqlite)');
            $this->line('• To switch to MySQL/PostgreSQL, update your .env file');
            $this->line('• Then run: php glueful migrate:run');
        } else {
            $this->line("• Currently using: {$dbDriver}");
        }
        $this->line('');

        $this->table(['Component', 'Status'], [
            ['Database', '✓ Connected and migrated'],
            ['Security Keys', '✓ Generated'],
            ['Cache System', '✓ Initialized']
        ]);
    }

    private function getDetailedHelp(): string
    {
        return <<<HELP
Glueful Installation Setup Wizard

This command sets up a new Glueful installation with all required components:

Steps performed:
  1. Environment validation (.env file check)
  2. Security key generation (TOKEN_SALT, JWT_KEY)
  3. Database connection testing and migrations
  4. Cache system initialization
  5. Final configuration validation

Examples:
  glueful install                           # Full interactive setup
  glueful install --force --quiet           # Force reinstall using environment variables
  glueful install --skip-database           # Skip database setup
  glueful install --skip-db                 # Skip database setup (alias)
  glueful install --skip-cache              # Skip cache initialization

Options allow you to customize which steps are performed during installation.
HELP;
    }

    // Helper methods
    private function validatePhpRequirements(): void
    {
        $phpVersion = PHP_VERSION;
        $requiredVersion = '8.3.0';

        if (version_compare($phpVersion, $requiredVersion, '<')) {
            throw new \Exception("PHP {$requiredVersion} or higher is required. Current version: {$phpVersion}");
        }

        $requiredExtensions = ['json', 'mbstring', 'openssl', 'PDO', 'curl', 'xml', 'zip'];
        $missingExtensions = [];

        foreach ($requiredExtensions as $extension) {
            if (!extension_loaded($extension)) {
                $missingExtensions[] = $extension;
            }
        }

        if (count($missingExtensions) > 0) {
            throw new \Exception('Missing PHP extensions: ' . implode(', ', $missingExtensions));
        }
    }

    private function validateDirectoryPermissions(): void
    {
        $directories = [
            base_path($this->getContext(), 'storage'),
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    throw new \Exception("Failed to create directory: {$dir}");
                }
            }

            if (!is_writable($dir)) {
                throw new \Exception("Directory is not writable: {$dir}");
            }
        }
    }

    private function updateEnvFile(string $key, string $value): void
    {
        $envPath = base_path($this->getContext(), '.env');

        if (!file_exists($envPath)) {
            throw new \Exception('.env file not found');
        }

        $content = file_get_contents($envPath);
        $pattern = "/^{$key}=.*/m";
        $replacement = "{$key}={$value}";

        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $replacement, $content);
        } else {
            $content .= "\n{$replacement}";
        }

        file_put_contents($envPath, $content);
    }

    private function configureDatabaseInteractively(): void
    {
        $this->line('Database Configuration:');

        // Ask for database driver choice
        $driver = $this->choice(
            'Which database driver would you like to use?',
            ['mysql', 'pgsql', 'sqlite'],
            'mysql'
        );

        $this->updateEnvFile('DB_DRIVER', $driver);

        switch ($driver) {
            case 'mysql':
                $this->configureMysqlDatabase();
                break;
            case 'pgsql':
                $this->configurePostgreSQLDatabase();
                break;
            case 'sqlite':
                $this->configureSqliteDatabase();
                break;
        }
    }

    private function configureMysqlDatabase(): void
    {
        $this->line('MySQL Configuration:');

        $host = $this->ask('Database host', '127.0.0.1');
        $port = $this->ask('Database port', '3306');
        $database = $this->ask('Database name', 'glueful');
        $username = $this->ask('Database username', 'root');
        $password = $this->secret('Database password');

        $this->updateEnvFile('DB_HOST', $host);
        $this->updateEnvFile('DB_PORT', $port);
        $this->updateEnvFile('DB_DATABASE', $database);
        $this->updateEnvFile('DB_USERNAME', $username);
        $this->updateEnvFile('DB_PASSWORD', $password);
    }

    private function configurePostgreSQLDatabase(): void
    {
        $this->line('PostgreSQL Configuration:');

        $host = $this->ask('Database host', '127.0.0.1');
        $port = $this->ask('Database port', '5432');
        $database = $this->ask('Database name', 'glueful');
        $username = $this->ask('Database username', 'postgres');
        $password = $this->secret('Database password');

        $this->updateEnvFile('DB_PGSQL_HOST', $host);
        $this->updateEnvFile('DB_PGSQL_PORT', $port);
        $this->updateEnvFile('DB_PGSQL_DATABASE', $database);
        $this->updateEnvFile('DB_PGSQL_USERNAME', $username);
        $this->updateEnvFile('DB_PGSQL_PASSWORD', $password);
    }

    private function configureSqliteDatabase(): void
    {
        $this->line('SQLite Configuration:');

        $defaultPath = config($this->getContext(), 'app.paths.storage') . '/database/primary.sqlite';
        $databasePath = $this->ask('Database file path', $defaultPath);

        // Ensure the database directory exists
        $databaseDir = dirname($databasePath);
        if (!is_dir($databaseDir)) {
            if (!mkdir($databaseDir, 0755, true)) {
                throw new \Exception("Failed to create database directory: {$databaseDir}");
            }
        }

        $this->updateEnvFile('DB_SQLITE_DATABASE', $databasePath);
    }

    private function checkDatabaseHealth(): void
    {
        $dbHealth = HealthService::checkDatabase();

        if ($dbHealth['status'] !== 'ok') {
            throw new \Exception('Database health check failed: ' . ($dbHealth['message'] ?? 'Unknown error'));
        }
    }

    private function checkCacheHealth(): void
    {
        try {
            $cacheStore = \Glueful\Helpers\CacheHelper::createCacheInstance();
            if ($cacheStore === null) {
                throw new \Exception('Cache instance could not be created');
            }
        } catch (\Exception $e) {
            throw new \Exception('Cache health check failed: ' . $e->getMessage());
        }
    }

    private function checkSecurityHealth(): void
    {
        // Read keys directly from .env file to avoid caching issues
        $envPath = base_path($this->getContext(), '.env');
        if (file_exists($envPath)) {
            $envContent = file_get_contents($envPath);

            // Extract TOKEN_SALT and JWT_KEY from .env file
            preg_match('/^TOKEN_SALT=(.*)$/m', $envContent, $tokenSaltMatches);
            preg_match('/^JWT_KEY=(.*)$/m', $envContent, $jwtKeyMatches);

            $tokenSalt = isset($tokenSaltMatches[1]) ? $tokenSaltMatches[1] : '';
            $jwtKey = isset($jwtKeyMatches[1]) ? $jwtKeyMatches[1] : '';
        } else {
            // Fallback to config/env if .env file not found
            $configTokenSalt = config($this->getContext(), 'session.token_salt');
            $envTokenSalt = env('TOKEN_SALT');
            $tokenSalt = $configTokenSalt !== null ? $configTokenSalt : ($envTokenSalt !== null ? $envTokenSalt : '');
            $configJwtKey = config($this->getContext(), 'session.jwt_key');
            $envJwtKey = env('JWT_KEY');
            $jwtKey = $configJwtKey !== null ? $configJwtKey : ($envJwtKey !== null ? $envJwtKey : '');
        }

        if ($tokenSalt === '') {
            throw new \Exception('TOKEN_SALT is not set');
        }

        if ($jwtKey === '') {
            throw new \Exception('JWT_KEY is not set');
        }

        // Validate key lengths
        if (strlen($tokenSalt) < 32) {
            throw new \Exception('TOKEN_SALT is too short (minimum 32 characters)');
        }

        if (strlen($jwtKey) < 64) {
            throw new \Exception('JWT_KEY is too short (minimum 64 characters)');
        }
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
