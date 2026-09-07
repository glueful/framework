<?php

declare(strict_types=1);

namespace Glueful\Installer;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;

/**
 * Read-only "should the installer run?" helpers. Best-effort: never throws and never requires a
 * reachable DB. The app's own `installed` lock (an admin exists) is separate and app-side.
 */
final class InstallState
{
    public function __construct(
        private readonly string $basePath,
        private readonly ?ApplicationContext $context = null,
    ) {
    }

    public function hasEnv(): bool
    {
        return is_file($this->basePath . '/.env');
    }

    /**
     * First run is complete once the installer has written all three security keys. Boot uses
     * this to tell "never installed" (quiet, self-bootstrapping) from "installed but broken"
     * (fail loudly): a production checkout without keys has not been through provision yet.
     */
    public function isInstalled(): bool
    {
        if (!$this->hasEnv()) {
            return false;
        }
        $env = new EnvWriter($this->basePath . '/.env');
        foreach (['APP_KEY', 'JWT_KEY', 'TOKEN_SALT'] as $key) {
            if (($env->get($key) ?? '') === '') {
                return false;
            }
        }

        return true;
    }

    public function isDatabaseConfigured(): bool
    {
        $env = new EnvWriter($this->basePath . '/.env');
        return $this->hasEnv() && ($env->get('DB_DRIVER') ?? '') !== '';
    }

    /**
     * True when nothing has been migrated yet — including when no DB is configured or the
     * configured DB is unreachable (best-effort; never throws).
     */
    public function migrationsPending(): bool
    {
        if (!$this->isDatabaseConfigured()) {
            return true;
        }
        try {
            $connection = Connection::fromContext($this->context);
            $tables = $connection->getSchemaBuilder()->getTables();
            // No migrations table => nothing migrated yet.
            return !in_array('migrations', $tables, true);
        } catch (\Throwable) {
            return true; // unreachable/misconfigured => treat as pending, do not throw
        }
    }
}
