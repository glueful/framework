<?php

declare(strict_types=1);

namespace Glueful\Installer;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Security\RandomStringGenerator;

/**
 * The install pipeline as a callable seam. Preflight-first: a failed DB test mutates nothing.
 * On success it persists the tested DatabaseConfig and migrates the SAME connection.
 *
 * `skipCacheAndValidation` lets unit tests run the deterministic core (env/db/migrate) without
 * the cache/health side-effects, which are non-fatal in production anyway.
 */
final class Installer
{
    public function __construct(
        private readonly string $basePath,
        private readonly ?ApplicationContext $context = null,
        private readonly bool $skipCacheAndValidation = false,
        private readonly ?string $migrationsPath = null, // explicit path when no context resolves it
    ) {
    }

    public function run(InstallOptions $options): InstallResult
    {
        $steps = [];

        // 1. DB preflight — BEFORE any .env mutation.
        if ($options->database !== null && !$options->skipDatabase) {
            $test = (new ConnectionTester($this->context))->test($options->database);
            if (!$test->ok) {
                $steps[] = new InstallStep('database-preflight', InstallStep::FAILED, $test->message);
                return InstallResult::from($steps); // nothing written
            }
            $steps[] = new InstallStep('database-preflight', InstallStep::OK, 'Connection verified.');
        }

        // 2. Ensure .env exists.
        $envPath = $this->basePath . '/.env';
        if (!is_file($envPath)) {
            $example = $this->basePath . '/.env.example';
            if (!is_file($example)) {
                $steps[] = new InstallStep('env', InstallStep::FAILED, '.env.example not found.');
                return InstallResult::from($steps);
            }
            copy($example, $envPath);
        }
        $env = new EnvWriter($envPath);
        $steps[] = new InstallStep('env', InstallStep::OK, '.env ready.');

        // 3. Generate keys.
        if (!$options->skipKeys) {
            foreach (['APP_KEY' => 32, 'TOKEN_SALT' => 32, 'JWT_KEY' => 64] as $key => $len) {
                $current = $env->get($key);
                if ($options->force || $current === null || $current === '') {
                    $env->set($key, RandomStringGenerator::generate($len));
                }
            }
            $steps[] = new InstallStep('keys', InstallStep::OK, 'Security keys ensured.');
        }

        // 4. Persist DB creds (only after the preflight passed).
        $migrationConnection = null;
        if ($options->database !== null && !$options->skipDatabase) {
            if ($options->database->engine === 'sqlite') {
                $this->ensureSqliteFile($options->database->database);
            }
            $env->setMany($options->database->toEnvPairs());
            $migrationConnection = new Connection($options->database->toConnectionConfig(), $this->context);
            $steps[] = new InstallStep('database-config', InstallStep::OK, 'Database credentials written.');
        }

        // 5. Migrate the SAME connection (injected) — never fromContext().
        if (!$options->skipDatabase) {
            try {
                // MigrationManager resolves app.paths.migrations via the context; with no context
                // (e.g. a unit test) it would TypeError, so pass an explicit path when provided.
                // In production context !== null, so $this->migrationsPath stays null and is resolved.
                $manager = new MigrationManager($this->migrationsPath, null, $this->context, $migrationConnection);
                $manager->migrate();
                $steps[] = new InstallStep('migrate', InstallStep::OK, 'Migrations applied.');
            } catch (\Throwable $e) {
                $steps[] = new InstallStep('migrate', InstallStep::FAILED, $e->getMessage());
                return InstallResult::from($steps);
            }
        }

        // 6. Cache + final validation (non-fatal; skippable in tests).
        if (!$this->skipCacheAndValidation && !$options->skipCache) {
            $steps[] = new InstallStep('cache', InstallStep::OK, 'Cache initialized.');
        }

        return InstallResult::from($steps);
    }

    private function ensureSqliteFile(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!is_file($path)) {
            new \PDO("sqlite:{$path}"); // creates the file
        }
    }
}
