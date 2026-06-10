# Storage Provider Registry + Upload Storage Refinement -- Design Note

**Status:** Draft v3 -- S3/Azure/GCS extracted to first-party packs; native blob-URL exposure resolved; upload disk metadata bug fixed separately | **Scope:** `config/storage.php`, `config/uploads.php`, `src/Storage/*`, `src/Uploader/*`, `src/Controllers/UploadController.php`, `src/Container/Providers/StorageProvider.php`, blob storage metadata, and the boundary and extraction path for provider-specific storage packages. Rich media processing remains owned by `glueful/media`; CDN purging remains owned by `glueful/cdn`.

## Problem

Glueful core already owns a solid upload/storage primitive:

- `StorageManager` resolves Flysystem disks from `config/storage.php`.
- `PathGuard` validates storage paths.
- `UrlGenerator` maps stored paths to public/CDN URLs.
- `FileUploader` stores multipart/base64 uploads and persists blob metadata.
- `FlysystemStorage` adapts core storage to the upload-facing `StorageInterface`.
- `UploadController` exposes blob upload, retrieval, metadata, delete, signed URL, ETag, range request, and image-variant delegation.
- `blobs` is core-owned schema for upload metadata.

That means a new `glueful/storage-s3` extension today would mostly duplicate logic that already lives in the framework. Core already has hardcoded support for `local`, `memory`, `s3`, `azure`, and `gcs` drivers in `StorageManager::createDisk()`, plus S3 presigned URL logic in `FlysystemStorage::getSignedUrl()`.

The issue is not missing storage capability. The issue is **provider knowledge is embedded directly in core**, which makes future provider packs awkward:

1. **Driver construction is hardcoded.** `StorageManager::createDisk()` has a `match` for `local`, `memory`, `s3`, `azure`, and `gcs`. Extensions cannot register a new disk driver without editing core.
2. **Provider dependency checks are hardcoded.** `diskExists()` knows adapter classes for S3, Azure, and GCS.
3. **Signed URL behavior is not a seam.** `FlysystemStorage::getSignedUrl()` special-cases S3 and falls back to `getUrl()` for everything else. R2/MinIO/Spaces can ride the S3 path, but GCS/Azure/native provider behavior cannot plug in cleanly.
4. **Provider health checks are not a seam.** There is no storage diagnostics contract for `storage:test` style checks.
5. **Cloud write behavior is inferred from driver names.** `FlysystemStorage::isCloudDisk()` checks `s3`, `gcs`, and `azure` to avoid atomic temp+move. A future provider needs core edits.
6. **The per-upload disk metadata bug has been carved out and fixed.** When `UploadController` creates a per-request `new FileUploader(storageDriver: $disk, ...)`, `FileUploader::saveBlobRecord()` previously recorded:

   ```php
   'storage_type' => (string) ($this->getConfig('uploads.disk', $this->storageDriver) ?? 'uploads')
   ```

   Because `uploads.disk` usually exists, the saved `storage_type` could reflect config rather than the actual `$this->storageDriver`. The bug fix now resolves one effective disk and uses it for storage initialization plus blob/file metadata. The broader registry work must keep that behavior covered by regression tests.

## Goal

Keep storage and uploads as core primitives, but make provider-specific behavior extensible.

Core should own:

- storage contracts and disk resolution;
- path safety;
- upload validation;
- blob metadata lifecycle;
- local/memory reference drivers;
- generic URL generation;
- generic signed application URLs for `/blobs/{uuid}`;
- range/ETag/streaming response behavior;
- a clean extension seam for provider drivers.

Provider packages should own:

- optional Composer dependencies;
- provider-specific adapter construction;
- provider-specific signed object URLs;
- provider diagnostics;
- provider config presets and docs.

## Non-Goals

- Do not extract uploads from core.
- Do not create storage provider extensions before the registry seam exists.
- Do not move `blobs` schema out of core.
- Do not move media/image processing back into core.
- Do not make core require AWS/GCS/Azure SDKs.
- Do not introduce Laravel-style storage facades or static APIs.

## Design

### 1. Add a storage driver registry

Introduce a small core registry that maps a disk `driver` string to a factory object.

Proposed files:

```text
src/Storage/Contracts/StorageDriverFactoryInterface.php
src/Storage/Contracts/StorageDriverRegistryInterface.php
src/Storage/StorageDriverRegistry.php
src/Storage/Drivers/LocalStorageDriverFactory.php
src/Storage/Drivers/MemoryStorageDriverFactory.php
```

Factory contract:

```php
namespace Glueful\Storage\Contracts;

use League\Flysystem\FilesystemOperator;

interface StorageDriverFactoryInterface
{
    public function driver(): string;

    /**
     * @param array<string, mixed> $config Disk config from storage.disks.{name}
     */
    public function create(array $config): FilesystemOperator;

    /**
     * Return true when required optional classes/config are available.
     *
     * @param array<string, mixed> $config
     */
    public function available(array $config): bool;

    /**
     * @param array<string, mixed> $config
     * @return array{
     *   supports_atomic_move?: bool,
     *   supports_native_signed_urls?: bool,
     *   cloud?: bool
     * }
     */
    public function features(array $config): array;
}
```

Registry contract:

```php
namespace Glueful\Storage\Contracts;

interface StorageDriverRegistryInterface
{
    public function register(string $driver, StorageDriverFactoryInterface $factory): void;

    public function has(string $driver): bool;

    public function get(string $driver): StorageDriverFactoryInterface;
}
```

`StorageManager` then becomes:

```php
public function __construct(
    array $config,
    PathGuard $pathGuard,
    ?StorageDriverRegistryInterface $drivers = null
) {
    $this->drivers = $drivers ?? StorageDriverRegistry::withBuiltIns();
}
```

And `createDisk()` becomes registry-based:

```php
$driver = (string) ($config['driver'] ?? '');
if (!$this->drivers->has($driver)) {
    // Names the missing pack, e.g. "Install it with: composer require glueful/storage-s3"
    throw UnsupportedStorageDriverException::forDriver($driver);
}

return $this->drivers->get($driver)->create($config);
```

Core registers only the dependency-free **reference factories**:

- `local` (the default disk driver)
- `memory` (test driver)

`s3`, `azure`, and `gcs` are **extracted to first-party provider packs as part of this change** (`glueful/storage-s3`, `glueful/storage-gcs`, `glueful/storage-azure`) -- consistent with the lean-core direction (like `glueful/users`, `glueful/aegis`, `glueful/media`). Moving the obvious providers through the seam is the dogfooding proof that the registry actually works (the same way `glueful/users` validated `UserProviderInterface`); a seam with no providers running through it is just indirection. Core no longer carries any SDK-coupled factory code.

**Missing-driver UX is load-bearing -- and lives in the right place.** `has($driver)` is a plain boolean registry check and `available($config)` is a dependency/config probe; **neither throws**. The pointed, package-naming error belongs where resolution actually fails -- `StorageManager::createDisk()` / `diskExists()` -- via a dedicated `UnsupportedStorageDriverException::forDriver($driver)` that carries a package suggestion, e.g. `Unsupported disk driver 's3'. Install it with: composer require glueful/storage-s3`. A bare "unsupported driver" string is not enough; the exception must name the pack (mirroring the `glueful/media` namespace-map guidance). The driver -> package suggestion map for the first-party drivers (`s3`/`gcs`/`azure`) lives in core so the message works even though the factories don't.

Registration should use the framework's existing additive service-list mechanism: tagged iterator definitions.

- Core and extensions register each factory as a service.
- Each factory service is tagged as `storage.driver_factory`.
- `StorageProvider` reads `$container->get('storage.driver_factory')`, then registers each factory into `StorageDriverRegistryInterface`.
- Factories expose their driver name through `driver(): string`. Tag metadata can be revisited later if the container supports it, but current `TaggedIteratorDefinition` entries only carry service and priority.

Duplicate policy:

- Built-ins register first.
- Extension factories register after built-ins.
- Last registered wins for the same driver name.

That preserves the current extension override model while still allowing many provider packs to contribute factories independently. `StorageDriverRegistry::register()` should overwrite an existing factory for the same driver and log/debug-report the replacement when diagnostics are available.

### 2. Add storage feature metadata

Avoid checking `driver in ['s3', 'gcs', 'azure']` throughout upload code.

The base factory contract owns only driver identity, construction, availability, and feature metadata. `features()` is intentionally small. Provider-specific behavior goes behind optional capability interfaces, not a fat factory contract.

`FlysystemStorage::store()` should use this feature instead of `isCloudDisk()`:

- if `supports_atomic_move === false`, write directly;
- otherwise use `StorageManager::putStream()` temp+move.

Default feature behavior:

- `supports_atomic_move` defaults to `true` when missing.
- cloud/object-store factories must explicitly return `supports_atomic_move => false`.
- `supports_native_signed_urls` defaults to `false`.
- `cloud` defaults to `false`.

Defaulting atomic moves to `true` keeps local/custom drivers on the current safe temp+move path. A cloud provider pack that forgets to opt out should fail loudly in tests or diagnostics rather than silently changing local-safe behavior.

Canonical features per driver (`local`/`memory` in core; `s3`/`gcs`/`azure` declared by their first-party packs):

| Driver | `supports_atomic_move` | Owner | Notes |
|---|---:|---|---|
| `local` | true | core | current safe temp+move behavior |
| `memory` | true | core | test driver |
| `s3` | false | `glueful/storage-s3` | avoid CopyObject failures on S3-compatible stores |
| `gcs` | false | `glueful/storage-gcs` | cloud object store |
| `azure` | false | `glueful/storage-azure` | cloud object store |

### 3. Add a native signed URL seam

Application signed blob URLs already work through `UploadController::signedUrl()` and `/blobs/{uuid}`. That is a core feature and should stay.

Native provider object URLs are separate. Add:

```php
namespace Glueful\Storage\Contracts;

interface NativeSignedUrlProviderInterface
{
    /**
     * @param array<string, mixed> $diskConfig
     */
    public function temporaryUrl(string $path, int $ttl, array $diskConfig): ?string;
}
```

Factories may implement this optional capability interface. Do not add native signing methods to `StorageDriverFactoryInterface`.

`FlysystemStorage::getSignedUrl()` should ask the registry/factory first:

```php
$factory = $registry->get($driver);
if ($factory instanceof NativeSignedUrlProviderInterface) {
    return $factory->temporaryUrl($path, $expiry, $cfg) ?? $this->getUrl($path);
}

return $this->getUrl($path);
```

Move the current S3 presigned URL code into the S3 factory. This keeps provider-specific SDK logic out of `FlysystemStorage`.

**Public blob API exposure (opt-in, visibility-scoped).** Lemma -- a media-heavy CMS that benefits from serving objects straight from the bucket/CDN instead of proxying every blob through the app -- is a concrete consumer, so the blob API *may* surface a native object-store URL. But it is **additive, never a replacement**, because it changes the security model:

- The app-signed `/blobs/{uuid}` URL stays the always-available, access-controlled path (enforces visibility + auth + revocation).
- The blob metadata / signed-url response gains an **optional** `native_url` field, populated only when (a) the disk's factory implements `NativeSignedUrlProviderInterface`, **and** (b) config permits it for that blob -- falling back to the app-signed URL otherwise (the `?? getUrl()` path above).
- **Config is a policy, not a boolean**: per-disk opt-in, default **off**, scoped by **visibility**. `public` blobs may return a native/direct URL (the whole point -- bandwidth offload). `private` blobs may opt in only with a **bounded TTL**, and the trade-off must be documented loudly: a native presigned URL is a time-boxed **bearer token** that bypasses app-side access control and revocation.
- Default behavior is unchanged (app-signed), so existing consumers are unaffected; Lemma opts its *public* media disk in.

### 4. Add storage diagnostics

Add a health-check contract:

```php
namespace Glueful\Storage\Contracts;

interface StorageHealthCheckInterface
{
    /**
     * @param array<string, mixed> $diskConfig
     * @return array{ok: bool, message: string, details?: array<string, mixed>}
     */
    public function check(string $disk, array $diskConfig): array;
}
```

Factories may implement this optional capability interface. Do not add health-check methods to `StorageDriverFactoryInterface`.

Add a core command:

```text
php glueful storage:test [disk]
```

Behavior:

- **Read-only by default** -- never mutates a bucket without an explicit flag.
- without `disk`, test all configured disks;
- show whether the driver is registered;
- show whether required optional classes are installed (factory `available()`);
- a non-mutating liveness probe where cheap (e.g. a HEAD/list via the health-check capability);
- **`--write`** opts into the full write/read/delete smoke test (creates and removes a temp object); without it, nothing is written;
- never print secrets.

This is useful for Lemma because media-heavy content platforms fail badly when storage credentials or bucket policy are wrong.

### 5. Preserve fixed upload disk metadata precedence

`FileUploader` now exposes one internal method for resolving the effective upload disk:

```php
private function effectiveDisk(): string
{
    if ($this->storageDriver !== null && $this->storageDriver !== '') {
        return $this->storageDriver;
    }

    $uploadDisk = $this->getConfig('uploads.disk');
    if (is_string($uploadDisk) && $uploadDisk !== '') {
        return $uploadDisk;
    }

    $defaultDisk = $this->getConfig('storage.default');
    if (is_string($defaultDisk) && $defaultDisk !== '') {
        return $defaultDisk;
    }

    return 'uploads';
}
```

It is used in:

- `initializeStorage()`
- `saveFileRecord()`
- `saveBlobRecord()`

This fixes per-request disk overrides and keeps `blobs.storage_type` trustworthy. The future registry refactor must not regress this behavior.

### 6. Revisit blob metadata naming later

`blobs.storage_type` currently stores the disk name, not the storage driver type. For example, it may store `uploads`, `s3`, `media`, or `private_assets`.

That works, but the name is slightly misleading. The rename itself is a small migration -- the real cost is *timing*: once a published consumer (Lemma) reads `storage_type` over a public surface, renaming becomes a cross-package breaking migration. So **gate the decision on Lemma's timeline, not "later"** -- decide before Lemma depends on the column publicly:

- **If the rename happens before Lemma reads it:** rename to `storage_disk` (the configured disk name); add a denormalized `driver` column only if diagnostics need it. One clean migration, no downstream break.
- **If Lemma will read it first:** keep `storage_type`, document it as a disk identifier (not a driver), and treat any later rename as a coordinated breaking change across core + Lemma.

### 7. Ship the first-party provider packs with this change

The registry seam ships **together with** its first-party provider packs -- this *is* the extraction, not a future maybe.

First-party packs (part of this change):

```text
glueful/storage-s3      (also covers R2 / MinIO / Spaces / Wasabi via presets)
glueful/storage-gcs
glueful/storage-azure
```

Later / optional packs (not part of this change):

```text
glueful/storage-sftp
```

Each pack should provide:

- Composer dependency for the adapter/SDK;
- provider factory registered into `StorageDriverRegistryInterface`;
- native signed URL support where available;
- health check;
- config stub/preset;
- docs and env examples;
- no upload routes, no blob schema, no media processing.

R2/MinIO/Spaces/Wasabi are **S3-compatible presets owned by `glueful/storage-s3`** -- not separate drivers or packages. The S3 factory takes a preset/endpoint config; no `r2`/`minio`/etc. driver name is introduced.

## Integration With Lemma

Lemma should depend on the core upload/storage primitive rather than owning storage.

Lemma should:

- store media references as blob UUIDs;
- use `uploads.disk` or a Lemma-specific disk setting such as `lemma.media_disk`;
- rely on `glueful/media` for transforms/thumbnails;
- rely on `glueful/cdn` for cache purge;
- rely on native storage provider factories only when direct object-store URLs are desired.

Lemma should not:

- duplicate upload validation;
- create a separate media table unless it needs CMS-specific asset metadata;
- implement S3/R2/GCS clients directly;
- bypass blob access control for private assets.

## Implementation Plan

1. Add storage contracts + the resolution exception:
   - `StorageDriverFactoryInterface`
   - `StorageDriverRegistryInterface`
   - `NativeSignedUrlProviderInterface`
   - `StorageHealthCheckInterface`
   - keep `NativeSignedUrlProviderInterface` and `StorageHealthCheckInterface` as optional factory capabilities
   - `UnsupportedStorageDriverException` (thrown by `createDisk()`/`diskExists()`; `::forDriver($driver)` adds a driver -> package suggestion for first-party drivers)

2. Add `StorageDriverRegistry` and the **core reference factories only**:
   - `LocalStorageDriverFactory`
   - `MemoryStorageDriverFactory`
   - (the `S3`/`Azure`/`Gcs` factories move to their first-party packs -- step 9 -- not core.)

3. Rewire `StorageProvider`:
   - bind registry;
   - register built-in factories;
   - collect extension factories from the `storage.driver_factory` tagged iterator;
   - register built-ins first and tagged factories second so extension providers can override built-in driver names intentionally;
   - pass registry into `StorageManager`;
   - keep `storage` alias.

4. Rewire `StorageManager`:
   - remove hardcoded `match`;
   - delegate creation and availability to registry factories.

5. Rewire `FlysystemStorage`:
   - replace `isCloudDisk()` with factory features;
   - replace embedded S3 presigned URL logic with `NativeSignedUrlProviderInterface`.

6. Preserve effective disk precedence in `FileUploader` and keep the new regression test passing.

7. Add `storage:test` command.

8. Add/update documentation:
   - core storage docs;
   - provider factory authoring guide;
   - R2/MinIO/S3 examples;
   - upload disk metadata note.

9. Ship the first-party provider packs (`glueful/storage-s3` incl. R2/MinIO/Spaces/Wasabi presets, `glueful/storage-gcs`, `glueful/storage-azure`). Each: the SDK Composer dependency, its `*StorageDriverFactory` (tagged `storage.driver_factory`), `NativeSignedUrlProviderInterface` + `StorageHealthCheckInterface` where the provider supports them, a config preset, and docs/env examples. This is a **coordinated, breaking release** -- the core default-driver set shrinks to `local`/`memory`: the framework ships the registry + extraction with upgrade notes (and the pointed missing-driver error), the packs publish against that framework version, and apps upgrade then `composer require glueful/storage-{s3,gcs,azure}` for the driver they use. Same dance as the `users`/`media` extractions.

## Testing Requirements

Minimum coverage before calling this complete:

- `StorageManager` creates `local` and `memory` disks through the registry.
- `StorageManager::diskExists()` delegates to factory availability.
- Unknown driver produces the current clear unsupported-driver failure.
- Extensions can register a fake driver into the registry and resolve a disk.
- Extension factory tagged as `storage.driver_factory` is collected into the registry.
- Extension factory using an existing driver name overrides the built-in factory.
- `FlysystemStorage::store()` uses temp+move for local and direct write for non-atomic/cloud drivers.
- Missing `supports_atomic_move` defaults to `true`.
- `FlysystemStorage::getSignedUrl()` uses a fake native signer when present.
- `FileUploader` records the actual effective disk in `blobs.storage_type` when constructed with a non-default `storageDriver`.
- Existing upload route tests still pass without any rich media processor bound.
- `storage:test` redacts secrets and reports missing optional adapters cleanly.

## Decisions

1. **Storage stays in core.** Uploads, blobs, path safety, local storage, and generic disk resolution are framework primitives.
2. **Provider packs ship together with the registry seam.** The seam and the first-party `s3`/`gcs`/`azure` packs are one coordinated change -- packs don't precede the seam, and they don't wait indefinitely after it.
3. **S3/Azure/GCS are extracted to first-party packs as part of this change.** Core ships only `local`/`memory` reference factories; everything SDK-coupled moves to `glueful/storage-{s3,gcs,azure}` (lean-core, like users/aegis/media). The packs are the proof the seam works. It is a coordinated breaking release with upgrade notes and a pointed missing-driver error.
4. **Native object-store signed URLs are provider behavior.** Core keeps application signed `/blobs/{uuid}` URLs; provider factories own direct object-store temporary URLs.
5. **`blobs.storage_type`: value fixed now; rename decision gated on Lemma's timeline.** The metadata-precedence bug is already fixed. The rename to `storage_disk` is cheap as a migration but must be decided *before* Lemma reads the column publicly -- after that it is a cross-package breaking change. Decide on Lemma's schedule, not "later."
6. **Lemma consumes storage; it does not own storage.** Lemma should use blob UUIDs and storage disks, then integrate with media/CDN/search extensions.
7. **Factory capabilities are explicit interfaces.** `StorageDriverFactoryInterface` stays minimal; native signed URLs and health checks are opt-in contracts.
8. **Factory registration is additive.** Use the existing tagged-iterator pattern for `storage.driver_factory`; built-ins register first and extension factories can override by driver name.
9. **StorageManager constructor compatibility should be preserved.** Accept a nullable registry and default to built-ins so direct `new StorageManager($config, $pathGuard)` usage does not break.
10. **Native object-store URLs may be exposed in the blob API -- opt-in and visibility-scoped.** An additive `native_url` field, default off, per-disk policy: `public` blobs may serve direct provider URLs (bandwidth offload), `private` blobs only with a bounded TTL and a documented bearer-token trade-off. The app-signed `/blobs/{uuid}` URL stays the always-available, access-controlled path. Lemma is the concrete consumer that justifies exposing it now.
11. **`storage:test` is read-only by default.** It reports registration, adapter availability, and a non-mutating liveness probe; the write/read/delete smoke test requires an explicit `--write`. Never touch a bucket mutatingly by default.

## Open Questions

None outstanding -- all resolved below.

## Resolved (folded into Design / Decisions)

- **`storage:test` mutation** -> read-only by default; `--write` required for the write/read/delete smoke test (Decision 11, Design 4).
- **`storage_type` rename** -> gated on Lemma's timeline; rename to `storage_disk` before Lemma reads it publicly, else keep + document (Decision 5, Design 6).
- **S3/Azure/GCS placement** -> extracted to first-party packs as part of this change; core ships `local`/`memory` only (Decision 3, Design 1/7).
- **R2 driver shape** -> preset on the S3 factory (`glueful/storage-s3` covers R2/MinIO/Spaces/Wasabi), not a separate driver (Design 7).
- **Native provider signed URLs in the public blob API** -> exposed additively as an optional, per-disk, default-off, visibility-scoped `native_url` field; app-signed `/blobs/{uuid}` stays the access-controlled default (Decision 10, Design 3).
