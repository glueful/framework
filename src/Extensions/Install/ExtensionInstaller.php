<?php

declare(strict_types=1);

namespace Glueful\Extensions\Install;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionCatalog;
use Glueful\Support\Process\ComposerBinaryResolver;
use Glueful\Support\Process\PhpBinaryResolver;
use Glueful\Support\Process\ProcessRunner;
use Glueful\Support\Process\SymfonyProcessRunner;

/**
 * Validates an install request and runs `composer require` SYNCHRONOUSLY.
 *
 * The install is a blocking, foreground composer run in the request that triggered
 * it (WordPress-style): the caller waits, and on success the package appears in the
 * Installed list DISABLED — the operator enables it with the normal toggle (a later
 * request whose autoloader can already see the freshly-installed provider). No job
 * store, no queue, no detached process: those were fragile under web SAPIs.
 *
 * Guard order (fail fast, cheapest/most-decisive first): kill-switch → host
 * capability → package allowlist (name grammar + vendor prefix + catalog membership).
 */
final class ExtensionInstaller
{
    /** Composer package-name grammar (vendor/name, lowercase). */
    private const NAME_GRAMMAR = '/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9]([_.-]?[a-z0-9]+)*$/';

    private ProcessRunner $runner;
    private ComposerBinaryResolver $composer;
    private PhpBinaryResolver $php;

    public function __construct(
        private ApplicationContext $context,
        private ExtensionCatalog $catalog,
        private HostCapability $host,
        ?ProcessRunner $runner = null,
        ?ComposerBinaryResolver $composer = null,
        ?PhpBinaryResolver $php = null,
    ) {
        $this->runner = $runner ?? new SymfonyProcessRunner();
        $this->composer = $composer ?? new ComposerBinaryResolver();
        $this->php = $php ?? new PhpBinaryResolver($context);
    }

    /**
     * Install a package with a blocking `composer require`.
     *
     * @return array{status:string,package:string,exitCode:?int,output:string,error:?string}
     *   status is `installed` (success — enable separately) or `failed`.
     * @throws InstallDisabledException|HostNotWritableException|PackageNotAllowedException
     */
    public function install(string $package): array
    {
        if (!(bool) config($this->context, 'extensions.install.enabled', false)) {
            throw new InstallDisabledException('Extension installation is disabled.');
        }
        if (($cap = $this->host->forInstall()) !== null) {
            throw new HostNotWritableException($cap['reason'], $cap['detail']);
        }
        $this->assertAllowed($package);

        $composer = $this->composer->resolve();
        if ($composer === null) {
            return $this->result('failed', $package, null, '', 'composer binary not found');
        }

        // composer needs a writable HOME/COMPOSER_HOME; Apache/FPM frequently run
        // without HOME set, which makes composer bail ("HOME or COMPOSER_HOME ... must
        // be set"). Point it at storage/ and pass it EXPLICITLY in the child's env —
        // putenv() is a no-op when the web SAPI disables it, so relying on it is fragile.
        $home = storage_path($this->context, 'framework/composer');
        if (!is_dir($home)) {
            @mkdir($home, 0775, true);
        }
        $env = getenv();                       // complete parent env (PATH, etc.)
        $env['COMPOSER_HOME'] = $home;
        if (($env['HOME'] ?? '') === '') {
            $env['HOME'] = $home;
        }

        @set_time_limit(0); // composer require can run for minutes; don't let PHP time it out
        $timeout = (float) config($this->context, 'extensions.install.timeout', 600);
        // Invoke composer through an explicit CLI php — running the composer script
        // directly relies on `php` being on the web process's PATH, which fails under
        // Apache/nginx+FPM. `<php-cli> <composer> require …` works on every SAPI.
        $run = $this->runner->run(
            [$this->php->resolve(), $composer, 'require', $package, '--no-interaction', '--no-progress', '--no-ansi'],
            base_path($this->context),
            $timeout,
            null,
            $env,
        );

        if ($run['exitCode'] !== 0) {
            return $this->result('failed', $package, $run['exitCode'], $run['output'], 'composer require failed');
        }
        return $this->result('installed', $package, 0, $run['output'], null);
    }

    /**
     * @return array{status:string,package:string,exitCode:?int,output:string,error:?string}
     */
    private function result(string $status, string $package, ?int $exitCode, string $output, ?string $error): array
    {
        // Cap stored/echoed output so a noisy composer run can't bloat the response.
        if (strlen($output) > 64_000) {
            $output = substr($output, -64_000);
        }
        return compact('status', 'package', 'exitCode', 'output', 'error');
    }

    private function assertAllowed(string $package): void
    {
        $vendor = (string) config($this->context, 'extensions.install.vendor', 'glueful/');

        if (
            $package === ''
            || $package[0] === '-'                              // never let a name be read as a flag
            || !str_starts_with($package, $vendor)
            || preg_match(self::NAME_GRAMMAR, $package) !== 1
        ) {
            throw new PackageNotAllowedException("Invalid or non-{$vendor} package: {$package}");
        }

        foreach ($this->catalog->catalog() as $row) {
            if (($row['package'] ?? null) === $package) {
                return;
            }
        }
        throw new PackageNotAllowedException("Not an installable Glueful extension: {$package}");
    }
}
