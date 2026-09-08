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
            $pairs = $options->database->toEnvPairs();
            $env->setMany($pairs);
            // The process booted with whatever `.env` held before (a fresh create-project: the
            // sample's placeholders). Migrations receive the injected connection below, but any
            // that open their OWN connection (pack permission seeds, Aegis's role seed) read the
            // live environment and cached config — publish the credentials just written so they
            // see the real database, not "role your_database_user does not exist".
            $this->publishEnvironment($pairs);
            $migrationConnection = new Connection($options->database->toConnectionConfig(), $this->context);
            $steps[] = new InstallStep('database-config', InstallStep::OK, 'Database credentials written.');
        }

        // 5. Migrate the SAME connection (injected) — never fromContext(). A COMPLETE pass
        // (schema policy spec B4): with a context, the factory-built manager carries the app path
        // plus every manifest descriptor, so provision applies core schema in one custody
        // sequence — snapshot globalSources(), lock EVERY source in the snapshot, take the fresh
        // pending read inside the lock, and report truthfully from the run report. Context-less
        // (unit) installs keep a bare manager whose only global source is 'app' under the same
        // custody. The lock backend comes from the SAME connection that migrates, so the two can
        // never drift, and provision cannot race a concurrent migrate:run or enable.
        if (!$options->skipDatabase) {
            try {
                $manager = $this->context !== null
                    ? \Glueful\Extensions\Schema\MigrationManagerFactory::create($this->context, $migrationConnection)
                    : new MigrationManager($this->migrationsPath, null, null, $migrationConnection);
                $lockConnection = $migrationConnection ?? Connection::fromContext($this->context);
                $lock = \Glueful\Extensions\Schema\MigrationLockFactory::forConnection(
                    $lockConnection,
                    $this->context
                );
                $snapshot = $manager->globalSources();
                $handle = $lock->acquireAll($snapshot);
                try {
                    $report = $manager->migrateSources($snapshot);
                } finally {
                    $handle->release();
                }
                $failure = $report->firstFailure();
                if ($failure !== null) {
                    // Truthful failure: never report install success from a report carrying a
                    // failed migration. migrateSources() stopped at it, so later files stay
                    // pending for the next attempt.
                    $message = basename($failure['file']) . ' failed: ' . ($failure['error'] ?? 'unknown error');
                    if ($failure['requiresManualRepair']) {
                        $message .= ' — the driver could not roll this back atomically; manual repair'
                            . ' required before re-running provision (see migrate:verify).';
                    }
                    $steps[] = new InstallStep('migrate', InstallStep::FAILED, $message);
                    return InstallResult::from($steps);
                }
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

    /**
     * Make freshly written `.env` pairs visible to the running process: env(), getenv() and the
     * context's cached `database` config all answer with the new values from here on.
     *
     * @param array<string, string> $pairs
     */
    private function publishEnvironment(array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
        }
        $this->context?->forgetConfig('database');
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
