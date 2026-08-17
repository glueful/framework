<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Extensions\EnabledProviders;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ExtensionResolver;
use Glueful\Extensions\ExtensionStateWriter;
use Glueful\Extensions\PackageManifest;
use Glueful\Extensions\ProtectedProviders;
use Glueful\Extensions\ResolverError;
use Glueful\Support\Version;

/**
 * The ONE executor for extension enable/disable (schema policy spec B5): bootstrap-ordered,
 * lock-serialized, migrate-first / enable-last, with a truthful terminal state persisted in the
 * core-owned extension_operations record. CLI and HTTP surfaces all drive this class; it never
 * calls global migrate() and cannot apply an unrelated disabled extension's schema.
 *
 * ExtensionStateWriter is constructed INTERNALLY: it needs no configuration, and the architecture
 * test allowlists the class token to this file only — which is why it is not a constructor
 * parameter (a container provider would otherwise have to name it).
 */
class ExtensionSchemaExecutor
{
    private const OPERATIONS = 'extension_operations';
    private const BOOTSTRAP_SOURCE = 'glueful/framework:extensions';

    private readonly ExtensionStateWriter $writer;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly DescriptorInventory $inventory,
        private readonly MigrationManager $manager,
        private readonly SchemaReadiness $readiness,
        private readonly MigrationLockInterface $lock,
        private readonly Connection $db,
        private readonly int $lockWaitSeconds = 10,
    ) {
        $this->writer = new ExtensionStateWriter();
    }

    public function enable(
        string $package,
        string $actor,
        bool $dryRun = false,
        bool $backup = false
    ): ExtensionOperation {
        $this->assertBootstrapped();
        $provider = $this->providerOf($package);
        if (($refusal = ProtectedProviders::refusalFor($this->context, $provider)) !== null) {
            throw new \RuntimeException($refusal);
        }
        $current = EnabledProviders::from($this->context);
        $this->assertResolvable($package, [...$current, $provider]);

        $packageSources = array_map(
            static fn(MigrationDescriptor $d): string => $d->source(),
            $this->inventory->forPackage($package)
        );
        $coreSources = $this->pendingCoreSources();

        if ($dryRun) {
            $this->writer->enable($this->configPath(), $provider, dryRun: true, backup: $backup);
            return new ExtensionOperation(
                0,
                $package,
                'enable',
                'dry-run',
                ExtensionOperation::STATUS_SUCCEEDED,
                $actor
            );
        }

        $handle = $this->lock->acquireAll([...$coreSources, ...$packageSources], $this->lockWaitSeconds);
        try {
            $operation = $this->record($package, 'enable', 'migrating', $actor);

            foreach ([$coreSources, $packageSources] as $sources) {
                if ($sources === []) {
                    continue;
                }
                $report = $this->manager->migrateSources($sources);
                $failure = $report->firstFailure();
                if ($failure !== null) {
                    $status = $failure['requiresManualRepair']
                        ? ExtensionOperation::STATUS_MANUAL_REPAIR
                        : ExtensionOperation::STATUS_FAILED;
                    return $this->update($operation->with(
                        'migrating',
                        $status,
                        basename($failure['file']),
                        $failure['error']
                    ));
                }
            }

            foreach ($this->readiness->forPackage($package) as $source => $result) {
                if ($result['state'] !== ReadinessState::Ready) {
                    return $this->update($operation->with(
                        'verify-readiness',
                        ExtensionOperation::STATUS_FAILED,
                        null,
                        "{$source} not ready after migrate: " . implode('; ', $result['reasons'])
                    ));
                }
            }

            $this->writer->enable($this->configPath(), $provider, dryRun: false, backup: $backup);
            return $this->finishWithCacheRecompile($operation, 'enabled');
        } finally {
            $handle->release();
        }
    }

    public function disable(
        string $package,
        string $actor,
        bool $dryRun = false,
        bool $backup = false
    ): ExtensionOperation {
        $this->assertBootstrapped();
        $provider = $this->providerOf($package);
        if (($refusal = ProtectedProviders::refusalFor($this->context, $provider)) !== null) {
            throw new \RuntimeException($refusal);
        }
        $current = EnabledProviders::from($this->context);
        $proposed = array_values(array_filter($current, static fn(string $p): bool => $p !== $provider));
        // Preserve the disable lifecycle guarantee: removing a provider another enabled
        // extension depends on refuses with the resolver's message (DisableCommand behavior).
        $this->assertResolvable($package, $proposed, missingDependencyOnly: true);

        if ($dryRun) {
            $this->writer->disable($this->configPath(), $provider, dryRun: true, backup: $backup);
            return new ExtensionOperation(
                0,
                $package,
                'disable',
                'dry-run',
                ExtensionOperation::STATUS_SUCCEEDED,
                $actor
            );
        }

        $packageSources = array_map(
            static fn(MigrationDescriptor $d): string => $d->source(),
            $this->inventory->forPackage($package)
        );
        $handle = $this->lock->acquireAll($packageSources, $this->lockWaitSeconds);
        try {
            $operation = $this->record($package, 'disable', 'disabling', $actor);
            // Never any schema change: disabling preserves all tables and data.
            $this->writer->disable($this->configPath(), $provider, dryRun: false, backup: $backup);
            return $this->finishWithCacheRecompile($operation, 'disabled');
        } finally {
            $handle->release();
        }
    }

    /**
     * The recompile seam: the config write already succeeded, so a recompile failure is the
     * truthful enabled_cache_stale state, never a rollback.
     */
    protected function recompileProviderCache(): void
    {
        container($this->context)->get(ExtensionManager::class)->writeCacheNow();
    }

    private function finishWithCacheRecompile(ExtensionOperation $operation, string $step): ExtensionOperation
    {
        try {
            $this->recompileProviderCache();
        } catch (\Throwable $e) {
            return $this->update($operation->with(
                $step,
                ExtensionOperation::STATUS_CACHE_STALE,
                null,
                "Config written, but recompiling the provider cache failed: {$e->getMessage()}. "
                . "Re-run 'php glueful extensions:cache'."
            ));
        }
        return $this->update($operation->with($step, ExtensionOperation::STATUS_SUCCEEDED));
    }

    private function assertBootstrapped(): void
    {
        $descriptor = $this->inventory->bySource(self::BOOTSTRAP_SOURCE);
        if ($descriptor === null || $this->readiness->classify($descriptor) !== ReadinessState::Ready) {
            throw SchemaNotBootstrappedException::create();
        }
    }

    private function providerOf(string $package): string
    {
        if (!$this->inventory->isDeclared($package)) {
            throw UndeclaredSchemaException::for($package);
        }
        $candidates = (new PackageManifest($this->context))->getCandidates();
        $candidate = $candidates[$package] ?? null;
        if ($candidate === null) {
            throw new \RuntimeException("Extension package not installed: {$package}");
        }
        return $candidate->provider;
    }

    /** @param list<string> $proposed */
    private function assertResolvable(string $package, array $proposed, bool $missingDependencyOnly = false): void
    {
        $candidates = (new PackageManifest($this->context))->getCandidates();
        $result = (new ExtensionResolver())->resolve($candidates, $proposed, Version::VERSION);
        $errors = $result->errors;
        if ($missingDependencyOnly) {
            $errors = array_values(array_filter(
                $errors,
                static fn($e): bool => $e->kind === ResolverError::MISSING_DEPENDENCY
            ));
        }
        if ($errors !== []) {
            $lines = array_map(static fn($e): string => "[{$e->kind}] {$e->message}", $errors);
            throw new \RuntimeException(
                "Cannot change {$package}: " . implode(' ', $lines)
            );
        }
    }

    /** @return list<string> core descriptor sources that still have pending migrations */
    private function pendingCoreSources(): array
    {
        $coreSources = [];
        foreach ($this->inventory->all() as $descriptor) {
            if ($descriptor->mode === DescriptorMode::Core) {
                $coreSources[] = $descriptor->source();
            }
        }
        $pending = [];
        foreach ($this->manager->pendingForSources($coreSources) as $row) {
            $pending[$row['source']] = true;
        }
        return array_keys($pending);
    }

    private function configPath(): string
    {
        return config_path($this->context, 'extensions.php');
    }

    private function record(string $package, string $operation, string $step, string $actor): ExtensionOperation
    {
        // Outside any migration transaction: a rolled-back migration must not erase the audit.
        $this->db->table(self::OPERATIONS)->insert([
            'package' => $package,
            'operation' => $operation,
            'step' => $step,
            'status' => ExtensionOperation::STATUS_RUNNING,
            'actor' => $actor,
        ]);
        $row = $this->db->table(self::OPERATIONS)
            ->select(['id'])
            ->where('package', $package)
            ->orderBy('id', 'DESC')
            ->first();
        return new ExtensionOperation(
            (int) ($row['id'] ?? 0),
            $package,
            $operation,
            $step,
            ExtensionOperation::STATUS_RUNNING,
            $actor
        );
    }

    private function update(ExtensionOperation $operation): ExtensionOperation
    {
        $this->db->table(self::OPERATIONS)->where('id', $operation->id)->update([
            'step' => $operation->step,
            'status' => $operation->status,
            'failed_migration' => $operation->failedMigration,
            'error' => $operation->error,
        ]);
        return $operation;
    }
}
