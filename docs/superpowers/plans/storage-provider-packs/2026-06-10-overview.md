# Storage Provider Packs Extraction (S3 / GCS / Azure) -- Implementation Plan

> **REQUIRED SUB-SKILL:** `superpowers:test-driven-development` -- every task is failing-test-first (write the test, run it red, implement, run it green, then a text-only commit). Each task is independently green and reviewable; do not start a task before the previous one is green. Authoritative design spec (do not re-litigate its Decisions): `docs/superpowers/specs/2026-06-10-storage-provider-registry-design.md`.

> **DEPENDS ON PLAN A** -- `docs/superpowers/plans/2026-06-10-storage-driver-registry-implementation.md`. Plan A lands the **core registry seam + contracts** inside the framework:
> - `Glueful\Storage\Contracts\StorageDriverFactoryInterface` (`driver()` / `create()` / `available()` / `features()`),
> - `Glueful\Storage\Contracts\StorageDriverRegistryInterface` + `Glueful\Storage\StorageDriverRegistry`,
> - `Glueful\Storage\Contracts\NativeSignedUrlProviderInterface` (`temporaryUrl()`),
> - `Glueful\Storage\Contracts\StorageHealthCheckInterface` (`check()`),
> - `Glueful\Storage\Exceptions\UnsupportedStorageDriverException::forDriver()` (names the missing pack),
> - the core `local`/`memory` reference factories,
> - `StorageProvider` collecting the `storage.driver_factory` tagged iterator into the registry,
> - removal of the hardcoded `s3`/`azure`/`gcs` `match` arms from `StorageManager` and the embedded S3 presign code from `FlysystemStorage`.
>
> **This plan (Plan B) is the other half of the coordinated breaking release:** the three first-party packs that consume those core contracts and run through the seam. The packs are released **with** the framework version that ships Plan A (release-first: the framework tag exists before the packs pin it). These three packs do not exist as git repos yet, so this plan lives in the framework repo alongside the spec; each task's "Create" paths are written **relative to that pack's own future repo root**, noted per pack.

**Goal:** Extract the SDK-coupled `s3`, `gcs`, and `azure` storage drivers out of framework core into three first-party Composer packages -- `glueful/storage-s3`, `glueful/storage-gcs`, `glueful/storage-azure` -- each shipping a `StorageDriverFactoryInterface` implementation tagged `storage.driver_factory`, so Plan A's `StorageProvider` collects it into the `StorageDriverRegistry`. Each pack re-homes exactly the adapter-construction code, availability probe, native signed-URL logic, and health probe for its provider that core used to carry inline. `glueful/storage-s3` additionally ships R2 / MinIO / Spaces / Wasabi **presets** (not separate drivers or packs). Core ends up carrying zero AWS/GCS/Azure SDK code.

**Architecture:** Each pack is a standard `type: glueful-extension` package (house convention -- mirrors `glueful/tenancy` and `glueful/payvia`): a `composer.json` with the provider declared in `extra.glueful.provider`, a `ServiceProvider` subclass whose static `services()` registers the `*StorageDriverFactory` as a shared service **tagged `storage.driver_factory`**, and the factory class itself. The factory:
- `driver()` returns the canonical driver string (`s3` / `gcs` / `azure`);
- `create(array $config): FilesystemOperator` re-homes the migrated adapter construction;
- `available(array $config): bool` re-homes the `class_exists` probe from `StorageManager::diskExists()`;
- `features(array $config): array` returns `['supports_atomic_move' => false, 'cloud' => true]` plus `supports_native_signed_urls` where the provider supports it.

The S3 factory also `implements NativeSignedUrlProviderInterface` (re-homing `FlysystemStorage::getSignedUrl()`'s presign code) and `StorageHealthCheckInterface`. GCS and Azure implement these where the provider supports them. **The packs consume core contracts only -- they never edit core.** Plan A already made `StorageManager`/`FlysystemStorage` delegate to the registry; once a pack's factory is tagged and collected, the driver lights up.

**Tech Stack:** PHP 8.3+, PHPUnit 10.5, Flysystem v3. Each pack `require`s `glueful/framework` at the release that ships Plan A as a **dev** dependency (`require-dev`, matching the tenancy/payvia convention -- the framework is the test host, not a runtime dep of the pack code, which depends only on the core contracts) and its adapter SDK as a **runtime** `require`. Confirmed adapter packages + adapter classes (verified against the `class_exists` strings in `StorageManager::diskExists()` and the `createS3/Azure/GcsFilesystem()` bodies):

| Pack | Composer require | Adapter class | SDK/client class probed |
|---|---|---|---|
| `glueful/storage-s3` | `league/flysystem-aws-s3-v3` (^3.0) | `League\Flysystem\AwsS3V3\AwsS3V3Adapter` | `Aws\S3\S3Client` (pulled transitively via `aws/aws-sdk-php`) |
| `glueful/storage-gcs` | `league/flysystem-google-cloud-storage` (^3.0) | `League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter` | `Google\Cloud\Storage\StorageClient` (pulled transitively via `google/cloud-storage`) |
| `glueful/storage-azure` | `league/flysystem-azure-blob-storage` (^3.0) | `League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter` | `MicrosoftAzure\Storage\Blob\BlobRestProxy` (pulled transitively via `microsoft/azure-storage-blob`) |

Verification gate per task (run from the pack's own repo root): `composer test` (targeted green), `composer run analyze` (no new PHPStan errors), `composer run phpcs` (PSR-12). Commit messages are text-only (no `git` invocation is part of the plan).

---

## Split Plan Files

- `2026-06-10-overview.md` -- shared architecture, conventions, release coordination, self-review, and blockers.
- `2026-06-10-s3-provider-pack-implementation.md` -- `glueful/storage-s3`, including S3-compatible presets.
- `2026-06-10-gcs-provider-pack-implementation.md` -- `glueful/storage-gcs`.
- `2026-06-10-azure-provider-pack-implementation.md` -- `glueful/storage-azure`.

---

## File Structure

### `glueful/storage-s3` (repo root: the pack's own repository)

```text
composer.json
phpunit.xml                          # bootstrap vendor/autoload.php, one tests/ suite (mirrors tenancy)
README.md
config/storage-s3.php                # presets: r2 / minio / spaces / wasabi + env-driven defaults
src/
  StorageS3ServiceProvider.php       # Glueful\Extensions\StorageS3\StorageS3ServiceProvider
  S3StorageDriverFactory.php         # implements StorageDriverFactoryInterface,
                                     #            NativeSignedUrlProviderInterface,
                                     #            StorageHealthCheckInterface
  Presets/S3Presets.php              # applyPreset(array $config): array
tests/
  Unit/S3StorageDriverFactoryTest.php
  Unit/S3NativeSignedUrlTest.php
  Unit/S3HealthCheckTest.php
  Unit/S3PresetTest.php
  Integration/S3FactoryTagCollectionTest.php
```

### `glueful/storage-gcs`

```text
composer.json
phpunit.xml                          # same as the S3 pack's, suite name StorageGcs
README.md
config/storage-gcs.php
src/
  StorageGcsServiceProvider.php      # Glueful\Extensions\StorageGcs\StorageGcsServiceProvider
  GcsStorageDriverFactory.php        # implements StorageDriverFactoryInterface,
                                     #            NativeSignedUrlProviderInterface,
                                     #            StorageHealthCheckInterface
tests/
  Unit/GcsStorageDriverFactoryTest.php
  Unit/GcsNativeSignedUrlTest.php
  Unit/GcsHealthCheckTest.php
  Integration/GcsFactoryTagCollectionTest.php
```

### `glueful/storage-azure`

```text
composer.json
phpunit.xml                          # same as the S3 pack's, suite name StorageAzure
README.md
config/storage-azure.php
src/
  StorageAzureServiceProvider.php    # Glueful\Extensions\StorageAzure\StorageAzureServiceProvider
  AzureStorageDriverFactory.php      # implements StorageDriverFactoryInterface,
                                     #            NativeSignedUrlProviderInterface,
                                     #            StorageHealthCheckInterface
tests/
  Unit/AzureStorageDriverFactoryTest.php
  Unit/AzureNativeSignedUrlTest.php
  Unit/AzureHealthCheckTest.php
  Integration/AzureFactoryTagCollectionTest.php
```

### Framework repo (this plan's last section)

```text
docs/STORAGE_PROVIDER_PACKS.md       # migration / upgrade guide for the coordinated breaking release
```

---

## Shared conventions (all three packs)

- **Namespaces:** `Glueful\Extensions\StorageS3\`, `Glueful\Extensions\StorageGcs\`, `Glueful\Extensions\StorageAzure\` (PSR-4 `src/`), tests under `...\Tests\`.
- **`require-dev` framework pin:** `"glueful/framework": "^<RELEASE_WITH_PLAN_A>"`. Use the exact framework version that ships Plan A. Until that tag exists, the placeholder `^<RELEASE_WITH_PLAN_A>` is a **known blocker** (see Blockers) -- the pin is filled at release time, not before.
- **Tagging:** each factory service carries `'tags' => ['storage.driver_factory']` in the provider's `services()` array. The framework's `ContainerFactory` consumes that DSL key (`applyDslTags()` feeds the `TagCollector`, which yields the `TaggedIteratorDefinition` under the `storage.driver_factory` id); Plan A's `StorageProvider` only consumes that id -- its registry `FactoryDefinition` closure reads `$c->get('storage.driver_factory')` and registers each factory via `StorageDriverRegistryInterface::register($factory->driver(), $factory)`. Built-ins register first; extension factories register after, so last-registered-wins by driver name.
- **No upload routes, no blob schema, no media processing** in any pack (spec Non-Goals + section 7).
- **Test harness:** library tests extend `PHPUnit\Framework\TestCase` (not an app `TestCase`). Tag-collection integration tests run Plan A's registry closure -- Plan A exposes no public collection entry point and none is needed. Pattern (Plan A's own test pattern; full code in S3-8/GCS-7/AZ-7): build `(new \Glueful\Container\Providers\StorageProvider(new TagCollector(), ApplicationContext::forTesting($base)))->defs()`, inject the pack factory under the tagged-iterator id via `$defs['storage.driver_factory'] = new ValueDefinition('storage.driver_factory', [$factory])` (the same array shape `ContainerFactory` produces from the `'tags'` DSL key), build `new Container($defs)`, and resolve `StorageDriverRegistryInterface::class` -- resolution executes the registry `FactoryDefinition` closure Plan A ships. Add a separate package-manifest assertion in each integration test that reads the pack's own `composer.json` and verifies `type: glueful-extension` and `extra.glueful.provider` point at the provider class. Packs are separate repos: `$base` is `sys_get_temp_dir() . '/glueful-pack-' . uniqid()` with an empty `config/` dir created first (never assume the framework tree). These tests need `glueful/framework` from `require-dev` (already the case).
- **ASCII only** in code/docs (`--`, `->`).

---

---

# RELEASE COORDINATION

## Task REL-1 -- Migration / upgrade guide (framework repo)

This is the coordinated **breaking** release. Capture the sequence so app maintainers can follow it.

**Create** (framework repo) `docs/STORAGE_PROVIDER_PACKS.md` covering:

1. **What changed.** The core default-driver set shrinks to `local` + `memory`. `s3`/`gcs`/`azure` are no longer built into the framework -- they ship as `glueful/storage-s3`, `glueful/storage-gcs`, `glueful/storage-azure`. (Cross-link spec Decision 3 + section 7.)
2. **Release order (release-first).**
   1. Framework ships Plan A (registry seam + `local`/`memory` factories + removal of the cloud `match` arms + the `UnsupportedStorageDriverException::forDriver()` that names the pack) -- tagged `<RELEASE_WITH_PLAN_A>`, with upgrade notes.
   2. The three packs publish, each pinning `glueful/framework: ^<RELEASE_WITH_PLAN_A>` (dev) -- the framework tag already exists, so the pin is real.
   3. Apps upgrade the framework, then `composer require glueful/storage-{s3,gcs,azure}` for the driver(s) they use.
3. **The safety net.** An app that upgrades but forgets to require its driver gets a pointed failure at disk resolution: `UnsupportedStorageDriverException::forDriver('s3')` -> "Unsupported disk driver 's3'. Install it with: composer require glueful/storage-s3" (the driver->package map lives in core, Plan A). No silent misbehavior.
4. **Per-driver app steps.** For each of S3/GCS/Azure: the `composer require`, the unchanged `config/storage.php` disk block (driver name stays `s3`/`gcs`/`azure` -- no app config rename), and the new optional native-URL opt-in (`native_url`, default off, visibility-scoped -- spec section 3/Decision 10). S3-compatible stores (R2/MinIO/Spaces/Wasabi) use `driver: s3` + a `preset` key -- not a new driver.
5. **Verification.** `php glueful storage:test <disk>` (ships in core via Plan A) to confirm the driver is registered + adapter installed + reachable, read-only by default; `--write` for the full smoke test.
6. **Same dance as `users`/`media`.** Cross-link those extractions as precedent (lean-core direction).

**Steps**
- [ ] Write `docs/STORAGE_PROVIDER_PACKS.md` with the six sections above.
- [ ] (Docs-only -- no unit test.) Sanity: `composer run phpcs` on the framework (docs don't affect it) and a manual read-through against the spec. Commit (framework repo): `docs: storage provider packs migration/upgrade guide`.

---

# Self-Review

- **Every pack covers create / available / features / tag / native-url / health-check:**
  - S3: create (S3-3) - available (S3-2) - features (S3-4) - tag collection (S3-8) - native URL (S3-6) - health check (S3-7) - plus presets (S3-5).
  - GCS: create (GCS-3) - available (GCS-2) - features (GCS-4) - tag collection (GCS-7) - native URL (GCS-5) - health check (GCS-6).
  - Azure: create (AZ-3) - available (AZ-2) - features (AZ-4) - tag collection (AZ-7) - native URL (AZ-5) - health check (AZ-6).
- **Consumed contract names match the spec exactly:** `Glueful\Storage\Contracts\StorageDriverFactoryInterface`, `...\StorageDriverRegistryInterface`, `...\NativeSignedUrlProviderInterface`, `...\StorageHealthCheckInterface`; the exception `Glueful\Storage\Exceptions\UnsupportedStorageDriverException` (referenced, owned by Plan A); tag string `storage.driver_factory`; `FilesystemOperator` return type. All defined by Plan A; packs only consume them.
- **Tag-collection tests cover the registry seam and discovery metadata:** S3-8/GCS-7/AZ-7 resolve `StorageDriverRegistryInterface` through Plan A's actual `StorageProvider` registry closure (factory injected under the `storage.driver_factory` id -- the same array shape `ContainerFactory::applyDslTags()` yields), separately pin the `'tags'` DSL key in each pack's `services()`, and assert `composer.json` declares `type: glueful-extension` plus the correct `extra.glueful.provider`. No re-implemented collection loop anywhere.
- **No placeholders** except the deliberate `^<RELEASE_WITH_PLAN_A>` framework-version pin, which is a release-time fill documented as a blocker.
- **Features per spec table:** all three return `supports_atomic_move => false`, `cloud => true` (plus `supports_native_signed_urls => true`).
- **Scope guard:** no upload routes, no blob schema, no media processing in any pack (re-asserted in every README task).

# Blockers (noted in-plan; not asking)

1. **Plan A must land first and be tagged.** The `^<RELEASE_WITH_PLAN_A>` framework pin and the contract namespaces are unresolvable until Plan A ships and the framework is tagged. Fill the exact version into all three `composer.json` files (require-dev + `extra.glueful.requires.glueful`) at release time. Until then the packs cannot `composer install` against a published framework.
2. **Tag-collection tests couple to Plan A's container ids.** S3-8/GCS-7/AZ-7 execute Plan A's real registry `FactoryDefinition` closure by building `StorageProvider::defs()` and injecting the factory under the `storage.driver_factory` id via a `ValueDefinition` -- Plan A's own test pattern (it exposes no public collection entry point, and none is needed; the closure IS the collection path). The same tests also pin each pack's Composer discovery metadata. If Plan A renames the tagged-iterator id or the `StorageDriverRegistryInterface` binding, update the three tests' injection id / resolution target to match.
3. **Offline SDK construction assumptions.** Tests assume the AWS/GCS/Azure SDK clients construct lazily (no network on `new`). Verified true for the AWS S3 client and Azure `BlobRestProxy::createBlobService`; GCS `StorageClient` may need an inert key-file fixture (GCS-3/GCS-5 note that) and Azure may need the prebuilt-adapter fallback if its SDK validates the dev AccountKey strictly (AZ-3 note). These are contained per-task fallbacks, not plan blockers.
4. **Azure SAS helper API drift.** `BlobSharedAccessSignatureHelper`'s method signature varies across `microsoft/azure-storage-blob` versions; AZ-5 pins the assertion to `sig=` + resource path so the test survives signature adjustments, but the exact call may need tuning against the installed version.
