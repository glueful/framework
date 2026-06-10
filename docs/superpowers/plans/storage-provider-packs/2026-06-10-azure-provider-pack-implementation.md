# Azure Storage Provider Pack -- Implementation Plan

> Shared conventions, release coordination, self-review, and blockers live in `2026-06-10-overview.md`.
> Depends on Plan A: `../2026-06-10-storage-driver-registry-implementation.md`.

> Paths relative to the **`glueful/storage-azure` repo root**. Azure-specific code below.

## Task AZ-1 -- `composer.json` + provider skeleton

**Create** `composer.json` -- S3-1 shape with:
- `"name": "glueful/storage-azure"`, description "Azure Blob Storage driver for the Glueful framework.", keywords `["storage","azure","blob","flysystem","glueful"]`.
- `"require"`: `"php": "^8.3"`, `"league/flysystem-azure-blob-storage": "^3.0"`.
- autoload PSR-4 `"Glueful\\Extensions\\StorageAzure\\": "src/"`, tests `"...\\Tests\\": "tests/"`.
- `extra.glueful`: name `StorageAzure`, displayName "Azure Blob Storage Driver", provider `Glueful\\Extensions\\StorageAzure\\StorageAzureServiceProvider`.
- `require-dev` framework pin `^<RELEASE_WITH_PLAN_A>`.

**Create** `phpunit.xml` -- same as S3-1's (config, not code) with the testsuite name changed to `StorageAzure`.

**Create** `src/StorageAzureServiceProvider.php` -- same shape as `StorageS3ServiceProvider` (no `getName()` -- the base provider neither defines nor requires one); `services()` registers `AzureStorageDriverFactory::class` tagged `storage.driver_factory`; `register()` merges `config/storage-azure.php` under key `storage-azure`.

**Create** `src/AzureStorageDriverFactory.php` stub -- `driver()` returns `'azure'`; others throw `\LogicException('not implemented')`. Non-final, mirroring S3-1 (the probe seam added in AZ-2 is subclassed in the negative test).

**Create** `config/storage-azure.php` -> `<?php return [];` (no `presets` key -- presets are S3-only).

**Create** `tests/Unit/AzureStorageDriverFactoryTest.php`:
```php
public function testDriverNameIsAzure(): void
{
    $factory = new \Glueful\Extensions\StorageAzure\AzureStorageDriverFactory();
    self::assertSame('azure', $factory->driver());
    self::assertInstanceOf(
        \Glueful\Storage\Contracts\StorageDriverFactoryInterface::class,
        $factory
    );
}
```

**Steps**
- [ ] Create `composer.json` + `phpunit.xml`, then `composer install` (before it, `vendor/bin/phpunit` does not exist -- "no such file").
- [ ] Write test. Run: `vendor/bin/phpunit --filter testDriverNameIsAzure` -> **FAIL** (`Class not found` -- src files absent).
- [ ] Create the src/config files, `composer dump-autoload`. Run -> **PASS**. `analyze` + `phpcs` clean. Commit: `feat(azure): pack skeleton + driver() returns 'azure'`.

---

## Task AZ-2 -- `available()` re-homes the Azure `class_exists` probe

Re-home the Azure adapter availability probe for the supported creation path: requires
`League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter` **and**
`MicrosoftAzure\Storage\Blob\BlobRestProxy`. Do not report the alternate
`Azure\Storage\Blob\BlobRestProxy` namespace as available until `create()` can construct a disk with
it; `available()` must never be broader than `create()`.

**Modify** `src/AzureStorageDriverFactory.php` -- same probe-seam shape as S3-2:
```php
/**
 * Overridable probe seam (mirrors S3-2): the negative test subclasses
 * this to run available()'s false branch for real.
 */
protected function adapterPresent(): bool
{
    return $this->adapterClassPresent() && $this->microsoftProxyPresent();
}

protected function adapterClassPresent(): bool
{
    return class_exists('League\\Flysystem\\AzureBlobStorage\\AzureBlobStorageAdapter');
}

protected function microsoftProxyPresent(): bool
{
    return class_exists('MicrosoftAzure\\Storage\\Blob\\BlobRestProxy');
}

/** @param array<string, mixed> $config */
public function available(array $config): bool
{
    return $this->adapterPresent();
}
```

**Add tests**:
```php
public function testAvailableTrueWhenAdapterAndProxyPresent(): void
{
    self::assertTrue((new \Glueful\Extensions\StorageAzure\AzureStorageDriverFactory())->available([]));
}

public function testAvailableFalseWhenOnlyUnsupportedAlternateProxyNamespaceExists(): void
{
    // The old core probe mentioned Azure\Storage\Blob\BlobRestProxy as an
    // alternate namespace, but create() does not support that construction path.
    // The pack must not advertise availability for a driver it cannot create.
    $factory = new class extends \Glueful\Extensions\StorageAzure\AzureStorageDriverFactory {
        protected function adapterClassPresent(): bool
        {
            return true;
        }
        protected function microsoftProxyPresent(): bool
        {
            return false;
        }
    };
    self::assertFalse($factory->available([]));
}

public function testAvailableFalseWhenAdapterNotLoadable(): void
{
    $factory = new class extends \Glueful\Extensions\StorageAzure\AzureStorageDriverFactory {
        protected function adapterClassPresent(): bool
        {
            return false;
        }
    };
    self::assertFalse($factory->available([]));
}
```

**Steps**
- [ ] Add both tests. Run -> **FAIL**. Implement. Run -> **PASS**. `analyze` + `phpcs` clean. Commit: `feat(azure): available() probes AzureBlobStorageAdapter + BlobRestProxy`.

---

## Task AZ-3 -- `create()` re-homes Azure adapter construction

Re-home `StorageManager::createAzureFilesystem()` (lines 242-279) verbatim -- connection-string path first, prebuilt-adapter fallback.

**Modify** `src/AzureStorageDriverFactory.php` -- add `use League\Flysystem\Filesystem;` and:
```php
/** @param array<string, mixed> $config */
public function create(array $config): FilesystemOperator
{
    // Keep availability and creation aligned: the pack supports the
    // MicrosoftAzure BlobRestProxy construction path plus the explicit
    // prebuilt-adapter fallback below.
    if (!class_exists('League\\Flysystem\\AzureBlobStorage\\AzureBlobStorageAdapter')) {
        throw new \InvalidArgumentException(
            'Azure adapter not installed. Run: composer require glueful/storage-azure'
        );
    }

    foreach (['container'] as $key) {
        if (!isset($config[$key]) || $config[$key] === '') {
            throw new \InvalidArgumentException("Missing required Azure config: '{$key}'");
        }
    }

    // Prefer connection string if provided.
    if (
        isset($config['connection_string'])
        && $config['connection_string'] !== ''
        && class_exists('MicrosoftAzure\\Storage\\Blob\\BlobRestProxy')
    ) {
        $proxyClass = 'MicrosoftAzure\\Storage\\Blob\\BlobRestProxy';
        $client = $proxyClass::createBlobService((string) $config['connection_string']);
        $adapterClass = 'League\\Flysystem\\AzureBlobStorage\\AzureBlobStorageAdapter';
        $adapter = new $adapterClass($client, (string) $config['container'], (string) ($config['prefix'] ?? ''));
        assert($adapter instanceof \League\Flysystem\FilesystemAdapter);
        return new Filesystem($adapter);
    }

    // Fallback: require user to supply a prebuilt adapter.
    if (isset($config['adapter']) && $config['adapter'] instanceof \League\Flysystem\FilesystemAdapter) {
        return new Filesystem($config['adapter']);
    }

    throw new \InvalidArgumentException(
        "Unable to create Azure filesystem. Provide 'connection_string' or a prebuilt 'adapter'."
    );
}
```

**Add test**:
```php
public function testCreateBuildsFilesystemFromConnectionString(): void
{
    // A well-formed dev connection string -> BlobRestProxy builds offline (no call).
    $conn = 'DefaultEndpointsProtocol=https;AccountName=devstoreaccount1;'
        . 'AccountKey=' . base64_encode('dev-key-bytes-padding-padding-padding=') . ';'
        . 'BlobEndpoint=https://devstoreaccount1.blob.core.windows.net/';
    $fs = (new \Glueful\Extensions\StorageAzure\AzureStorageDriverFactory())->create([
        'container' => 'media',
        'connection_string' => $conn,
    ]);
    self::assertInstanceOf(\League\Flysystem\FilesystemOperator::class, $fs);
}

public function testCreateThrowsWhenContainerMissing(): void
{
    $this->expectException(\InvalidArgumentException::class);
    (new \Glueful\Extensions\StorageAzure\AzureStorageDriverFactory())->create(['connection_string' => 'x']);
}

public function testCreateThrowsWithoutConnectionStringOrAdapter(): void
{
    $this->expectException(\InvalidArgumentException::class);
    (new \Glueful\Extensions\StorageAzure\AzureStorageDriverFactory())->create(['container' => 'media']);
}
```

**Steps**
- [ ] Add tests. Run -> **FAIL**. Implement. Run -> **PASS** (`createBlobService` parses the string and builds the proxy without contacting Azure). `analyze` + `phpcs` clean. Commit: `feat(azure): create() re-homes Azure adapter construction`.

> Note: if the installed `microsoft/azure-storage-blob` validates the AccountKey base64 strictly and rejects the dev string, swap the test to the prebuilt-`adapter` fallback path (pass an in-memory `FilesystemAdapter` stub) to keep `create()` offline-green, and cover the connection-string branch via a mocked proxy class.

---

## Task AZ-4 -- `features()`

Per spec table: `azure` -> `supports_atomic_move => false`, `cloud => true`. Azure supports SAS URLs, so `supports_native_signed_urls => true`.

**Modify** `src/AzureStorageDriverFactory.php`:
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
    $f = (new \Glueful\Extensions\StorageAzure\AzureStorageDriverFactory())->features([]);
    self::assertFalse($f['supports_atomic_move']);
    self::assertTrue($f['cloud']);
    self::assertTrue($f['supports_native_signed_urls']);
}
```

**Steps**
- [ ] Add test. Run -> **FAIL**. Implement. Run -> **PASS**. `analyze` + `phpcs` clean. Commit: `feat(azure): features() declares cloud/non-atomic/native-url`.

---

## Task AZ-5 -- `NativeSignedUrlProviderInterface::temporaryUrl()` (Azure SAS URL)

Azure native signing is a Shared Access Signature (SAS) URL built from the account name/key with `BlobSharedAccessSignatureHelper`. Core never carried Azure presign code, so this is Azure-specific.

**Modify** `src/AzureStorageDriverFactory.php` -- add interface + method:
```php
use Glueful\Storage\Contracts\NativeSignedUrlProviderInterface;

class AzureStorageDriverFactory implements
    StorageDriverFactoryInterface,
    NativeSignedUrlProviderInterface
{
    // ...

    /** @param array<string, mixed> $diskConfig */
    public function temporaryUrl(string $path, int $ttl, array $diskConfig): ?string
    {
        $helperClass = 'MicrosoftAzure\\Storage\\Blob\\BlobSharedAccessSignatureHelper';
        if (!class_exists($helperClass)) {
            return null;
        }

        $account = (string) ($diskConfig['account_name'] ?? '');
        $key = (string) ($diskConfig['account_key'] ?? '');
        $container = (string) ($diskConfig['container'] ?? '');
        if ($account === '' || $key === '' || $container === '') {
            return null;
        }

        try {
            $seconds = $ttl > 0 ? $ttl : (int) ($diskConfig['signed_ttl'] ?? 3600);
            $prefix = (string) ($diskConfig['prefix'] ?? '');
            $blob = $prefix !== '' ? rtrim($prefix, '/') . '/' . ltrim($path, '/') : $path;

            /** @var object $helper */
            $helper = new $helperClass($account, $key);
            $sas = $helper->generateBlobServiceSharedAccessSignatureToken(
                'b',                                   // signed resource: blob
                $container . '/' . $blob,              // canonical resource path
                'r',                                   // read-only permission
                (new \DateTimeImmutable())->modify("+{$seconds} seconds"),
                new \DateTimeImmutable('now')
            );

            $endpoint = (string) ($diskConfig['blob_endpoint'] ?? "https://{$account}.blob.core.windows.net");
            return rtrim($endpoint, '/') . '/' . $container . '/' . ltrim($blob, '/') . '?' . $sas;
        } catch (\Throwable) {
            return null;
        }
    }
}
```

**Create** `tests/Unit/AzureNativeSignedUrlTest.php`:
```php
public function testTemporaryUrlReturnsNullWhenCredentialsMissing(): void
{
    self::assertNull(
        (new \Glueful\Extensions\StorageAzure\AzureStorageDriverFactory())
            ->temporaryUrl('x', 600, ['container' => 'media'])
    );
}

public function testImplementsNativeSignedUrlProvider(): void
{
    self::assertInstanceOf(
        \Glueful\Storage\Contracts\NativeSignedUrlProviderInterface::class,
        new \Glueful\Extensions\StorageAzure\AzureStorageDriverFactory()
    );
}

public function testTemporaryUrlBuildsSasUrlFromDevCredentials(): void
{
    $url = (new \Glueful\Extensions\StorageAzure\AzureStorageDriverFactory())->temporaryUrl(
        'uploads/file.jpg',
        600,
        [
            'container' => 'media',
            'account_name' => 'devstoreaccount1',
            'account_key' => base64_encode('dev-key-bytes-padding-padding-padding='),
        ]
    );
    self::assertIsString($url);
    self::assertStringContainsString('media/uploads/file.jpg', (string) $url);
    self::assertStringContainsString('sig=', (string) $url);
}
```

**Steps**
- [ ] Write tests. Run -> **FAIL** (method/interface absent). The null + interface tests must go green offline; the SAS-build test signs locally (no network).
- [ ] Implement interface + `temporaryUrl()`.
- [ ] Run -> **PASS**. If the installed SDK's `BlobSharedAccessSignatureHelper` signature differs (older versions expose `generateBlobServiceSharedAccessSignatureToken` with a different arg order), adjust the call to the installed signature and keep the test asserting `sig=` + the resource path. `analyze` + `phpcs` clean. Commit: `feat(azure): native SAS temporaryUrl via NativeSignedUrlProviderInterface`.

---

## Task AZ-6 -- `StorageHealthCheckInterface::check()`

**Modify** `src/AzureStorageDriverFactory.php` -- add interface + method:
```php
use Glueful\Storage\Contracts\StorageHealthCheckInterface;

class AzureStorageDriverFactory implements
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
                'message' => "Disk '{$disk}': Azure adapter/SDK not installed (composer require glueful/storage-azure).",
            ];
        }

        $container = (string) ($diskConfig['container'] ?? '');
        if ($container === '') {
            return ['ok' => false, 'message' => "Disk '{$disk}': missing 'container' config."];
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
                'details' => ['driver' => 'azure', 'container' => $container],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => "Disk '{$disk}': probe failed -- " . $e->getMessage()];
        }
    }
}
```

**Create** `tests/Unit/AzureHealthCheckTest.php`:
```php
public function testCheckFailsCleanlyWhenContainerMissing(): void
{
    $r = (new \Glueful\Extensions\StorageAzure\AzureStorageDriverFactory())
        ->check('media', ['connection_string' => 'x']);
    self::assertFalse($r['ok']);
    self::assertStringContainsString("missing 'container'", $r['message']);
}

public function testImplementsHealthCheck(): void
{
    self::assertInstanceOf(
        \Glueful\Storage\Contracts\StorageHealthCheckInterface::class,
        new \Glueful\Extensions\StorageAzure\AzureStorageDriverFactory()
    );
}
```

**Steps**
- [ ] Write tests. Run -> **FAIL**. Implement. Run -> **PASS**. `analyze` + `phpcs` clean. Commit: `feat(azure): read-only health check via StorageHealthCheckInterface`.

---

## Task AZ-7 -- tag collection resolves the factory into the registry

Same approach as S3-8: execute Plan A's REAL collection closure by building the framework `StorageProvider`'s defs and injecting the pack factory under the `storage.driver_factory` id; pin the `'tags'` DSL key in a companion unit assertion. Each pack is its own repo, so the test is complete here (no shared helper exists to reference).

**Create** `tests/Integration/AzureFactoryTagCollectionTest.php` (complete file):
```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\StorageAzure\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Container\Providers\StorageProvider;
use Glueful\Container\Providers\TagCollector;
use Glueful\Extensions\StorageAzure\AzureStorageDriverFactory;
use Glueful\Extensions\StorageAzure\StorageAzureServiceProvider;
use Glueful\Storage\Contracts\StorageDriverRegistryInterface;
use PHPUnit\Framework\TestCase;

final class AzureFactoryTagCollectionTest extends TestCase
{
    public function testServicesDslPinsTheDriverFactoryTag(): void
    {
        $services = StorageAzureServiceProvider::services();
        self::assertSame(
            ['storage.driver_factory'],
            $services[AzureStorageDriverFactory::class]['tags']
        );
    }

    public function testComposerManifestDeclaresGluefulExtensionProvider(): void
    {
        $json = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
        self::assertSame('glueful-extension', $json['type'] ?? null);
        self::assertSame(
            StorageAzureServiceProvider::class,
            $json['extra']['glueful']['provider'] ?? null
        );
    }

    public function testAzureFactoryIsCollectedIntoRegistryAndResolvesDisk(): void
    {
        // Packs are separate repos: throwaway base path + empty config/ dir.
        $base = sys_get_temp_dir() . '/glueful-pack-' . uniqid();
        mkdir($base . '/config', 0777, true);

        $provider = new StorageProvider(new TagCollector(), ApplicationContext::forTesting($base));
        $defs = $provider->defs();

        $factory = new AzureStorageDriverFactory();
        $defs['storage.driver_factory'] = new ValueDefinition('storage.driver_factory', [$factory]);

        // Resolving the registry executes Plan A's actual collection closure.
        $registry = (new Container($defs))->get(StorageDriverRegistryInterface::class);

        self::assertTrue($registry->has('azure'));
        self::assertSame($factory, $registry->get('azure'));

        // Reuse the AZ-3 dev connection string (offline-safe construction).
        $conn = 'DefaultEndpointsProtocol=https;AccountName=devstoreaccount1;'
            . 'AccountKey=' . base64_encode('dev-key-bytes-padding-padding-padding=') . ';'
            . 'BlobEndpoint=https://devstoreaccount1.blob.core.windows.net/';
        $fs = $registry->get('azure')->create([
            'container' => 'media',
            'connection_string' => $conn,
        ]);
        self::assertInstanceOf(\League\Flysystem\FilesystemOperator::class, $fs);
    }
}
```

**Steps**
- [ ] Write the test file. Run: `vendor/bin/phpunit tests/Integration/AzureFactoryTagCollectionTest.php` -> expect **PASS** (no new production code -- pins the AZ-1..AZ-6 seam plus Plan A's closure).
- [ ] TDD red check: temporarily typo the `services()` `'tags'` key -> DSL-pin test **FAILS**; revert. Temporarily change `driver()` to `'azurex'` -> collection test **FAILS** at `has('azure')`; revert.
- [ ] `analyze` + `phpcs` clean. Commit: `test(azure): tag collection resolves azure factory into the registry`.

---

## Task AZ-8 -- README + config + env examples

**Create** `README.md` + `config/storage-azure.php` example disk block: `AZURE_STORAGE_CONNECTION_STRING` (preferred), or `AZURE_STORAGE_ACCOUNT`/`AZURE_STORAGE_KEY` + `AZURE_STORAGE_CONTAINER`, optional `prefix`/`signed_ttl`/`blob_endpoint`. Document the native-URL opt-in (SAS) and that SAS needs `account_name`/`account_key`. `php glueful storage:test <disk>` example. No upload routes / blob schema / media docs.

**Steps**
- [ ] Write docs. Run `composer test` -> **PASS**. `phpcs` clean. Commit: `docs(azure): README, config, env examples`.

---
