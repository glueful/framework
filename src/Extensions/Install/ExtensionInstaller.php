<?php

declare(strict_types=1);

namespace Glueful\Extensions\Install;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionCatalog;
use Glueful\Support\Process\DetachedRunner;

/**
 * Validates an install request and launches the detached runner.
 *
 * Guard order (fail fast, cheapest/most-decisive first): kill-switch → host
 * capability → package allowlist (name grammar + vendor prefix + catalog
 * membership). Only after all pass is a job created and the runner spawned.
 */
final class ExtensionInstaller
{
    /** Composer package-name grammar (vendor/name, lowercase). */
    private const NAME_GRAMMAR = '/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9]([_.-]?[a-z0-9]+)*$/';

    public function __construct(
        private ApplicationContext $context,
        private ExtensionCatalog $catalog,
        private InstallJobStore $jobs,
        private HostCapability $host,
        private DetachedRunner $runner,
    ) {
    }

    /**
     * @return array{jobId:string,status:string}
     * @throws InstallDisabledException|HostNotWritableException|PackageNotAllowedException
     */
    public function start(string $package): array
    {
        if (!(bool) config($this->context, 'extensions.install.enabled', false)) {
            throw new InstallDisabledException('Extension installation is disabled.');
        }
        if (($cap = $this->host->forInstall()) !== null) {
            throw new HostNotWritableException($cap['reason'], $cap['detail']);
        }
        $this->assertAllowed($package);

        $jobId = $this->jobs->create($package);
        $this->runner->spawnInstall($jobId, $package);

        return ['jobId' => $jobId, 'status' => 'queued'];
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
