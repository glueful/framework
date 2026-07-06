<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\DTOs\ExtensionInstallData;
use Glueful\DTOs\ExtensionToggleData;
use Glueful\Extensions\EnabledProviders;
use Glueful\Extensions\ExtensionCatalog;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ExtensionResolver;
use Glueful\Extensions\ExtensionStateWriter;
use Glueful\Extensions\Install\ExtensionInstaller;
use Glueful\Extensions\Install\HostCapability;
use Glueful\Extensions\Install\HostNotWritableException;
use Glueful\Extensions\Install\InstallDisabledException;
use Glueful\Extensions\Install\PackageNotAllowedException;
use Glueful\Extensions\PackageManifest;
use Glueful\Http\Response;
use Glueful\Support\Version;
use Psr\Log\LoggerInterface;

/**
 * In-admin extensions manager API (browse / install / enable / disable).
 *
 * Mutating actions require the `system.config.edit` tier; reads require
 * `system.config.view`. Install is gated further by the kill-switch + host
 * capability inside {@see ExtensionInstaller}. All actions are audited.
 */
class ExtensionsController extends BaseController
{
    public function __construct(
        ApplicationContext $context,
        private ExtensionCatalog $catalog,
        private ExtensionInstaller $installer,
        private HostCapability $host,
        private ExtensionManager $extensions,
        private LoggerInterface $auditLog,
    ) {
        parent::__construct($context);
    }

    /** Installed extensions with enable/disable state. */
    public function index(): Response
    {
        $this->requirePermission('system.config.view');
        return $this->success(['installed' => $this->catalog->installed()]);
    }

    /** Installable extensions from Packagist (cached), annotated with local state. */
    public function catalog(): Response
    {
        $this->requirePermission('system.config.view');
        return $this->success(['catalog' => $this->catalog->catalog()]);
    }

    /**
     * Install a package synchronously (blocking `composer require`). On success the
     * extension is installed but DISABLED — enable it with {@see enable()}.
     */
    public function install(ExtensionInstallData $data): Response
    {
        $this->requirePermission('system.config.edit');
        try {
            $result = $this->installer->install($data->package);
        } catch (InstallDisabledException $e) {
            return $this->forbidden($e->getMessage());
        } catch (HostNotWritableException $e) {
            return Response::error($e->getMessage(), 409, ['reason' => $e->reason]);
        } catch (PackageNotAllowedException $e) {
            return $this->validationError(['package' => [$e->getMessage()]]);
        }

        if (($result['status'] ?? null) !== 'installed') {
            $this->audit('extension.install', $data->package, 'failed');
            return Response::error(
                is_string($result['error'] ?? null) ? $result['error'] : 'composer require failed',
                422,
                ['output' => is_string($result['output'] ?? null) ? $result['output'] : ''],
            );
        }
        $this->audit('extension.install', $data->package, 'installed');
        return $this->success($result, 'Extension installed — enable it to activate');
    }

    public function enable(ExtensionToggleData $data): Response
    {
        return $this->toggle($data->package, enable: true);
    }

    public function disable(ExtensionToggleData $data): Response
    {
        return $this->toggle($data->package, enable: false);
    }

    private function toggle(string $package, bool $enable): Response
    {
        $this->requirePermission('system.config.edit');

        if (($cap = $this->host->forToggle()) !== null) {
            return Response::error('Host not writable', 409, ['reason' => $cap['reason']]);
        }

        $candidates = (new PackageManifest($this->context))->getCandidates();
        $candidate = $candidates[$package] ?? null;
        if ($candidate === null) {
            return $this->notFound("Not an installed extension: {$package}");
        }
        $provider = $candidate->provider;
        $current = EnabledProviders::from($this->context);
        $proposed = $enable
            ? [...$current, $provider]
            : array_values(array_filter($current, static fn($p) => $p !== $provider));

        $result = (new ExtensionResolver())->resolve($candidates, $proposed, Version::VERSION);
        if ($result->hasErrors()) {
            return $this->validationError(['extension' => array_map(
                static fn($e) => "[{$e->kind}] {$e->message}",
                $result->errors,
            )]);
        }

        $writer = new ExtensionStateWriter();
        $configPath = config_path($this->context, 'extensions.php');
        $enable ? $writer->enable($configPath, $provider) : $writer->disable($configPath, $provider);
        $this->extensions->writeCacheNow();

        $this->audit($enable ? 'extension.enable' : 'extension.disable', $package, 'succeeded');
        return $this->success([
            'package' => $package,
            'provider' => $provider,
            'state' => $enable ? 'enabled' : 'available',
        ]);
    }

    private function audit(string $action, string $package, string $result): void
    {
        $this->auditLog->info($action, [
            'log_channel' => 'audit',
            'action' => $action,
            'actor_id' => $this->getCurrentUserUuid(),
            'resource_type' => 'extension',
            'resource_id' => $package,
            'result' => $result,
        ]);
    }
}
