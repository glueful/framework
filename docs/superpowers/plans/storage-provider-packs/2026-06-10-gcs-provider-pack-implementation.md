# GCS Storage Provider Pack -- Implementation Plan

> Shared conventions, release coordination, self-review, and blockers live in `2026-06-10-overview.md`.
> Depends on Plan A: `../2026-06-10-storage-driver-registry-implementation.md`.

> Paths relative to the **`glueful/storage-gcs` repo root**. Same structure as S3; GCS-specific code below (not "same as S3").

## Task GCS-1 -- `composer.json` + provider skeleton

**Create** `composer.json` -- identical shape to S3-1 with these fields changed:
- `"name": "glueful/storage-gcs"`, description "Google Cloud Storage driver for the Glueful framework.", keywords `["storage","gcs","google-cloud","flysystem","glueful"]`.
- `"require"`: `"php": "^8.3"`, `"league/flysystem-google-cloud-storage": "^3.0"`.
- autoload PSR-4 `"Glueful\\Extensions\\StorageGcs\\": "src/"`, tests `"...\\Tests\\": "tests/"`.
- `extra.glueful`: name `StorageGcs`, displayName "GCS Storage Driver", provider `Glueful\\Extensions\\StorageGcs\\StorageGcsServiceProvider`.
- same `require-dev` framework pin `^<RELEASE_WITH_PLAN_A>`.

**Create** `phpunit.xml` -- same as S3-1's (config, not code) with the testsuite name changed to `StorageGcs`.

**Create** `src/StorageGcsServiceProvider.php` -- same shape as `StorageS3ServiceProvider` (no `getName()` -- the base provider neither defines nor requires one), `services()` registers `GcsStorageDriverFactory::class` tagged `storage.driver_factory`, `register()` merges `config/storage-gcs.php` under key `storage-gcs`.

**Create** `src/GcsStorageDriverFactory.php` stub -- `driver()` returns `'gcs'`; `create()`/`available()`/`features()` throw `\LogicException('not implemented')`. Non-final, mirroring S3-1 (the probe seam added in GCS-2 is subclassed in the negative test).

**Create** `config/storage-gcs.php` -> `<?php return [];` (no `presets` key -- presets are S3-only).

**Create** `tests/Unit/GcsStorageDriverFactoryTest.php`:
```php
public function testDriverNameIsGcs(): void
{
    $factory = new \Glueful\Extensions\StorageGcs\GcsStorageDriverFactory();
    self::assertSame('gcs', $factory->driver());
    self::assertInstanceOf(
        \Glueful\Storage\Contracts\StorageDriverFactoryInterface::class,
        $factory
    );
}
```

**Steps**
- [ ] Create `composer.json` + `phpunit.xml`, then `composer install` (before it, `vendor/bin/phpunit` does not exist -- "no such file").
- [ ] Write test. Run: `vendor/bin/phpunit --filter testDriverNameIsGcs` -> **FAIL** (`Class not found` -- src files absent).
- [ ] Create the src/config files, `composer dump-autoload`.
- [ ] Run -> **PASS**. `analyze` + `phpcs` clean. Commit: `feat(gcs): pack skeleton + driver() returns 'gcs'`.

---

## Task GCS-2 -- `available()` re-homes the GCS `class_exists` probe

Re-home `StorageManager::diskExists()` GCS arm (lines 101-102): requires `League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter` **and** `Google\Cloud\Storage\StorageClient`.

**Modify** `src/GcsStorageDriverFactory.php` -- same probe-seam shape as S3-2:
```php
/**
 * Overridable probe seam (mirrors S3-2): the negative test subclasses
 * this to run available()'s false branch for real.
 */
protected function adapterPresent(): bool
{
    return class_exists('League\\Flysystem\\GoogleCloudStorage\\GoogleCloudStorageAdapter')
        && class_exists('Google\\Cloud\\Storage\\StorageClient');
}

/** @param array<string, mixed> $config */
public function available(array $config): bool
{
    return $this->adapterPresent();
}
```

**Add tests** to `tests/Unit/GcsStorageDriverFactoryTest.php`:
```php
public function testAvailableTrueWhenAdapterAndClientPresent(): void
{
    self::assertTrue((new \Glueful\Extensions\StorageGcs\GcsStorageDriverFactory())->available([]));
}

public function testAvailableFalseWhenAdapterNotLoadable(): void
{
    $factory = new class extends \Glueful\Extensions\StorageGcs\GcsStorageDriverFactory {
        protected function adapterPresent(): bool
        {
            return false;
        }
    };
    self::assertFalse($factory->available([]));
}
```

**Steps**
- [ ] Add both tests. Run: `vendor/bin/phpunit --filter testAvailable` -> **FAIL** (throws).
- [ ] Implement. Run -> **PASS**. `analyze` + `phpcs` clean. Commit: `feat(gcs): available() probes GoogleCloudStorageAdapter + StorageClient`.

---

## Task GCS-3 -- `create()` re-homes GCS adapter construction

Re-home `StorageManager::createGcsFilesystem()` (lines 284-329) verbatim (it already handles both adapter constructor signatures).

**Modify** `src/GcsStorageDriverFactory.php` -- add `use League\Flysystem\Filesystem;` and:
```php
/** @param array<string, mixed> $config */
public function create(array $config): FilesystemOperator
{
    if (!$this->available($config)) {
        throw new \InvalidArgumentException(
            'GCS adapter not installed. Run: composer require glueful/storage-gcs'
        );
    }

    foreach (['bucket'] as $key) {
        if (!isset($config[$key]) || $config[$key] === '') {
            throw new \InvalidArgumentException("Missing required GCS config: '{$key}'");
        }
    }

    $clientConfig = [];
    if (isset($config['key_file']) && $config['key_file'] !== '') {
        $clientConfig['keyFilePath'] = (string) $config['key_file'];
    }
    if (isset($config['project_id']) && $config['project_id'] !== '') {
        $clientConfig['projectId'] = (string) $config['project_id'];
    }

    $clientClass = 'Google\\Cloud\\Storage\\StorageClient';
    $client = new $clientClass($clientConfig);

    // Try common constructor signatures across adapter versions.
    $prefix = (string) ($config['prefix'] ?? '');
    $adapterClass = 'League\\Flysystem\\GoogleCloudStorage\\GoogleCloudStorageAdapter';
    try {
        // Signature: (StorageClient $client, string $bucket, string $prefix = '')
        $adapter = new $adapterClass($client, (string) $config['bucket'], $prefix);
    } catch (\Throwable) {
        // Signature: (Bucket $bucket, string $prefix = '')
        $bucket = $client->bucket((string) $config['bucket']);
        $adapter = new $adapterClass($bucket, $prefix);
    }
    assert($adapter instanceof \League\Flysystem\FilesystemAdapter);

    return new Filesystem($adapter);
}
```

**Add test**:
```php
public function testCreateBuildsFilesystem(): void
{
    // No key_file => application-default-credentials path; StorageClient ctor is lazy.
    $fs = (new \Glueful\Extensions\StorageGcs\GcsStorageDriverFactory())->create([
        'bucket' => 'test-bucket',
        'project_id' => 'demo-project',
    ]);
    self::assertInstanceOf(\League\Flysystem\FilesystemOperator::class, $fs);
}

public function testCreateThrowsWhenBucketMissing(): void
{
    $this->expectException(\InvalidArgumentException::class);
    (new \Glueful\Extensions\StorageGcs\GcsStorageDriverFactory())->create(['project_id' => 'x']);
}
```

**Steps**
- [ ] Add tests. Run: `vendor/bin/phpunit --filter testCreate` -> **FAIL**.
- [ ] Implement. Run -> **PASS** (StorageClient construction is lazy -- no network). `analyze` + `phpcs` clean. Commit: `feat(gcs): create() re-homes GCS adapter construction`.

> Note: if the GCS SDK in CI requires real credentials even to construct `StorageClient` without a key file, pass an explicit `'key_file'` pointing at a fixture service-account JSON in `tests/fixtures/` (well-formed but inert) so construction stays offline. Add the fixture in this task if the bare-ctor test cannot stay green.

---

## Task GCS-4 -- `features()`

Per spec table: `gcs` -> `supports_atomic_move => false`, `cloud => true`. GCS supports signed URLs (V4 signing), so `supports_native_signed_urls => true`.

**Modify** `src/GcsStorageDriverFactory.php`:
```php
/**
 * @param array<string, mixed> $config
 * @return array{supports_atomic_move: bool, supports_native_signed_urls: bool, cloud: bool}
 */
public function features(array $config): array
{
    return [
        'supports_atomic_move' => false,
        'supports_native_signed_urls' => true,
        'cloud' => true,
    ];
}
```

**Add test**:
```php
public function testFeaturesDeclareCloudNonAtomicNativeUrls(): void
{
    $f = (new \Glueful\Extensions\StorageGcs\GcsStorageDriverFactory())->features([]);
    self::assertFalse($f['supports_atomic_move']);
    self::assertTrue($f['cloud']);
    self::assertTrue($f['supports_native_signed_urls']);
}
```

**Steps**
- [ ] Add test. Run -> **FAIL**. Implement. Run -> **PASS**. `analyze` + `phpcs` clean. Commit: `feat(gcs): features() declares cloud/non-atomic/native-url`.

---

## Task GCS-5 -- `NativeSignedUrlProviderInterface::temporaryUrl()` (GCS V4 signed URL)

GCS native signing goes through the GCS SDK's `Bucket::object()->signedUrl()` (the SDK signs locally with the service-account key). Core never carried GCS presign code, so this is the GCS-specific implementation (not a re-home).

**Modify** `src/GcsStorageDriverFactory.php` -- add interface + method:
```php
use Glueful\Storage\Contracts\NativeSignedUrlProviderInterface;

class GcsStorageDriverFactory implements
    StorageDriverFactoryInterface,
    NativeSignedUrlProviderInterface
{
    // ...

    /** @param array<string, mixed> $diskConfig */
    public function temporaryUrl(string $path, int $ttl, array $diskConfig): ?string
    {
        if (!class_exists('Google\\Cloud\\Storage\\StorageClient')) {
            return null;
        }

        $bucketName = (string) ($diskConfig['bucket'] ?? '');
        if ($bucketName === '') {
            return null;
        }

        try {
            $clientConfig = [];
            if (isset($diskConfig['key_file']) && $diskConfig['key_file'] !== '') {
                $clientConfig['keyFilePath'] = (string) $diskConfig['key_file'];
            }
            if (isset($diskConfig['project_id']) && $diskConfig['project_id'] !== '') {
                $clientConfig['projectId'] = (string) $diskConfig['project_id'];
            }

            $clientClass = 'Google\\Cloud\\Storage\\StorageClient';
            /** @var object $client */
            $client = new $clientClass($clientConfig);

            $seconds = $ttl > 0 ? $ttl : (int) ($diskConfig['signed_ttl'] ?? 3600);
            $prefix = (string) ($diskConfig['prefix'] ?? '');
            $objectName = $prefix !== '' ? rtrim($prefix, '/') . '/' . ltrim($path, '/') : $path;

            $object = $client->bucket($bucketName)->object($objectName);
            // V4 signing; expiry is a future timestamp.
            return (string) $object->signedUrl(time() + $seconds, ['version' => 'v4']);
        } catch (\Throwable) {
            return null;
        }
    }
}
```

**Create** `tests/Unit/GcsNativeSignedUrlTest.php`:
```php
public function testTemporaryUrlReturnsNullWhenBucketMissing(): void
{
    self::assertNull(
        (new \Glueful\Extensions\StorageGcs\GcsStorageDriverFactory())
            ->temporaryUrl('x', 600, ['project_id' => 'p'])
    );
}

public function testImplementsNativeSignedUrlProvider(): void
{
    self::assertInstanceOf(
        \Glueful\Storage\Contracts\NativeSignedUrlProviderInterface::class,
        new \Glueful\Extensions\StorageGcs\GcsStorageDriverFactory()
    );
}

public function testTemporaryUrlSignsWithFixtureKeyFile(): void
{
    // Requires a well-formed RSA service-account JSON fixture so local V4
    // signing succeeds offline. Skip if the fixture is absent in CI.
    $keyFile = __DIR__ . '/../fixtures/gcs-service-account.json';
    if (!is_file($keyFile)) {
        self::markTestSkipped('GCS signing fixture not present');
    }
    $url = (new \Glueful\Extensions\StorageGcs\GcsStorageDriverFactory())->temporaryUrl(
        'uploads/file.jpg',
        600,
        ['bucket' => 'b', 'project_id' => 'p', 'key_file' => $keyFile]
    );
    self::assertIsString($url);
    self::assertStringContainsString('X-Goog-Signature', (string) $url);
}
```

**Steps**
- [ ] Write tests. Run: `vendor/bin/phpunit tests/Unit/GcsNativeSignedUrlTest.php` -> **FAIL** (method/interface absent). The null-path + interface tests must go green without network; the signing test self-skips if no fixture.
- [ ] Implement interface + `temporaryUrl()`. Optionally add `tests/fixtures/gcs-service-account.json` (a generated, inert RSA keypair -- not a real credential) to exercise local signing.
- [ ] Run -> **PASS** (signing test green if fixture added, else skipped). `analyze` + `phpcs` clean. Commit: `feat(gcs): native V4 signedUrl via NativeSignedUrlProviderInterface`.

---

## Task GCS-6 -- `StorageHealthCheckInterface::check()`

**Modify** `src/GcsStorageDriverFactory.php` -- add interface + method:
```php
use Glueful\Storage\Contracts\StorageHealthCheckInterface;

class GcsStorageDriverFactory implements
    StorageDriverFactoryInterface,
    NativeSignedUrlProviderInterface,
    StorageHealthCheckInterface
{
    // ...

    /**
     * @param array<string, mixed> $diskConfig
     * @return array{ok: bool, message: string, details?: array<string, mixed>}
     */
    public function check(string $disk, array $diskConfig): array
    {
        if (!$this->available($diskConfig)) {
            return [
                'ok' => false,
                'message' => "Disk '{$disk}': GCS adapter/SDK not installed (composer require glueful/storage-gcs).",
            ];
        }

        $bucket = (string) ($diskConfig['bucket'] ?? '');
        if ($bucket === '') {
            return ['ok' => false, 'message' => "Disk '{$disk}': missing 'bucket' config."];
        }

        try {
            $fs = $this->create($diskConfig);
            $prefix = (string) ($diskConfig['prefix'] ?? '');
            foreach ($fs->listContents($prefix, false) as $_) {
                break;
            }

            return [
                'ok' => true,
                'message' => "Disk '{$disk}': reachable.",
                'details' => ['driver' => 'gcs', 'bucket' => $bucket],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => "Disk '{$disk}': probe failed -- " . $e->getMessage()];
        }
    }
}
```

**Create** `tests/Unit/GcsHealthCheckTest.php`:
```php
public function testCheckFailsCleanlyWhenBucketMissing(): void
{
    $r = (new \Glueful\Extensions\StorageGcs\GcsStorageDriverFactory())
        ->check('media', ['project_id' => 'p']);
    self::assertFalse($r['ok']);
    self::assertStringContainsString("missing 'bucket'", $r['message']);
}

public function testCheckNeverLeaksKeyFilePathSecrets(): void
{
    $r = (new \Glueful\Extensions\StorageGcs\GcsStorageDriverFactory())->check('media', [
        'bucket' => 'b',
        'project_id' => 'p',
        'key_file' => '/secret/path/SUPERSECRET.json',
    ]);
    // Probe fails offline, but the literal secret path must not appear verbatim
    // unless the SDK itself surfaces it -- assert our own message wrapper is clean.
    self::assertFalse($r['ok']);
    self::assertStringNotContainsString('SUPERSECRET', $r['message']);
}

public function testImplementsHealthCheck(): void
{
    self::assertInstanceOf(
        \Glueful\Storage\Contracts\StorageHealthCheckInterface::class,
        new \Glueful\Extensions\StorageGcs\GcsStorageDriverFactory()
    );
}
```

**Steps**
- [ ] Write tests. Run -> **FAIL**. Implement. Run -> **PASS**. `analyze` + `phpcs` clean. Commit: `feat(gcs): read-only health check via StorageHealthCheckInterface`.

---

## Task GCS-7 -- tag collection resolves the factory into the registry

Same approach as S3-8: execute Plan A's REAL collection closure by building the framework `StorageProvider`'s defs and injecting the pack factory under the `storage.driver_factory` id; pin the `'tags'` DSL key in a companion unit assertion. Each pack is its own repo, so the test is complete here (no shared helper exists to reference).

**Create** `tests/Integration/GcsFactoryTagCollectionTest.php` (complete file):
```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\StorageGcs\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Container\Providers\StorageProvider;
use Glueful\Container\Providers\TagCollector;
use Glueful\Extensions\StorageGcs\GcsStorageDriverFactory;
use Glueful\Extensions\StorageGcs\StorageGcsServiceProvider;
use Glueful\Storage\Contracts\StorageDriverRegistryInterface;
use PHPUnit\Framework\TestCase;

final class GcsFactoryTagCollectionTest extends TestCase
{
    public function testServicesDslPinsTheDriverFactoryTag(): void
    {
        $services = StorageGcsServiceProvider::services();
        self::assertSame(
            ['storage.driver_factory'],
            $services[GcsStorageDriverFactory::class]['tags']
        );
    }

    public function testComposerManifestDeclaresGluefulExtensionProvider(): void
    {
        $json = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
        self::assertSame('glueful-extension', $json['type'] ?? null);
        self::assertSame(
            StorageGcsServiceProvider::class,
            $json['extra']['glueful']['provider'] ?? null
        );
    }

    public function testGcsFactoryIsCollectedIntoRegistryAndResolvesDisk(): void
    {
        // Packs are separate repos: throwaway base path + empty config/ dir.
        $base = sys_get_temp_dir() . '/glueful-pack-' . uniqid();
        mkdir($base . '/config', 0777, true);

        $provider = new StorageProvider(new TagCollector(), ApplicationContext::forTesting($base));
        $defs = $provider->defs();

        $factory = new GcsStorageDriverFactory();
        $defs['storage.driver_factory'] = new ValueDefinition('storage.driver_factory', [$factory]);

        // Resolving the registry executes Plan A's actual collection closure.
        $registry = (new Container($defs))->get(StorageDriverRegistryInterface::class);

        self::assertTrue($registry->has('gcs'));
        self::assertSame($factory, $registry->get('gcs'));

        // Per the GCS-3 note: add a 'key_file' pointing at the inert fixture
        // service-account JSON if the bare ctor cannot stay offline-green in CI.
        $fs = $registry->get('gcs')->create(['bucket' => 'b', 'project_id' => 'p']);
        self::assertInstanceOf(\League\Flysystem\FilesystemOperator::class, $fs);
    }
}
```

**Steps**
- [ ] Write the test file. Run: `vendor/bin/phpunit tests/Integration/GcsFactoryTagCollectionTest.php` -> expect **PASS** (no new production code -- pins the GCS-1..GCS-6 seam plus Plan A's closure).
- [ ] TDD red check: temporarily typo the `services()` `'tags'` key -> DSL-pin test **FAILS**; revert. Temporarily change `driver()` to `'gcsx'` -> collection test **FAILS** at `has('gcs')`; revert.
- [ ] `analyze` + `phpcs` clean. Commit: `test(gcs): tag collection resolves gcs factory into the registry`.

---

## Task GCS-8 -- README + config + env examples

**Create** `README.md` + `config/storage-gcs.php` example disk block: `GCS_BUCKET`, `GCS_PROJECT_ID`, `GCS_KEY_FILE` (path to service-account JSON), optional `prefix`/`signed_ttl`. Document the native-URL opt-in (V4 signed URLs) and that signing needs a key file. `php glueful storage:test <disk>` example. No upload routes / blob schema / media docs.

**Steps**
- [ ] Write docs. Run `composer test` -> **PASS**. `phpcs` clean. Commit: `docs(gcs): README, config, env examples`.

---
