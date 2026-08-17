<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Extensions\EnabledProviders;
use Glueful\Extensions\PackageManifest;
use Glueful\Services\FileFinder;

/**
 * The sole registrar for descriptor-backed migration sources (schema policy spec B1): builds the
 * inventory (framework built-ins + every declared manifest descriptor), registers each exactly
 * once, and installs the call-time global source policy. Provider boot never registers a
 * described path — ServiceProvider::loadMigrationsFrom() validates and returns for them.
 */
final class MigrationManagerFactory
{
    private function __construct()
    {
    }

    public static function inventory(ApplicationContext $context): DescriptorInventory
    {
        return DescriptorInventory::fromManifest(
            new PackageManifest($context),
            self::frameworkRoot(),
            new FileFinder()
        );
    }

    public static function create(ApplicationContext $context, ?Connection $connection = null): MigrationManager
    {
        // Mirror config/app.php's shipped default so a context without that config entry
        // (bare bootstraps, tests) still resolves the app migrations dir deterministically.
        $appMigrations = \function_exists('config')
            ? (config($context, 'app.paths.migrations') ?? base_path($context, 'database/migrations'))
            : base_path($context, 'database/migrations');
        $manager = new MigrationManager((string) $appMigrations, new FileFinder(), $context, $connection);
        $inventory = self::inventory($context);
        foreach ($inventory->all() as $descriptor) {
            $manager->registerDescriptor($descriptor, $inventory->pathOf($descriptor));
        }
        // Enabled packages are computed at EACH call from current context state — a capture here
        // would freeze the enabled list at manager creation and let a mid-process enable/disable
        // desynchronize global runs from reality.
        $manager->setGlobalSourcePolicy(static function () use ($context): array {
            $providerToPackage = (new PackageManifest($context))->providerPackages();
            $packages = [];
            foreach (EnabledProviders::from($context) as $provider) {
                $package = $providerToPackage[$provider] ?? null;
                if ($package !== null) {
                    $packages[] = $package;
                }
            }
            return $packages;
        });
        return $manager;
    }

    private static function frameworkRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
