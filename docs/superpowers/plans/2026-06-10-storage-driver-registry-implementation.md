# Storage Driver Registry + Seam + Diagnostics -- Implementation Plan (Plan A)

REQUIRED SUB-SKILL: superpowers:subagent-driven-development

## Goal

Make storage provider behavior extensible without keeping SDK-coupled provider knowledge in core.
Introduce a **storage driver registry** that maps a disk `driver` string to a factory object, keep
only the dependency-free `local`/`memory` reference factories in core, and route all disk
construction, availability probing, atomic-move decisions, native signed URLs, and diagnostics
through small contracts. This is **Plan A** of a coordinated, breaking two-plan release: Plan A
ships the seam (this plan); **Plan B** ships the first-party provider packs
(`glueful/storage-s3`, `glueful/storage-gcs`, `glueful/storage-azure`). They ship together; after
upgrade, apps `composer require glueful/storage-{s3,gcs,azure}` for the driver they use.

Source spec: `docs/superpowers/specs/2026-06-10-storage-provider-registry-design.md` (authoritative).

## Architecture

- **Contracts** (`src/Storage/Contracts/`): `StorageDriverFactoryInterface` (identity + construction
  + availability + feature metadata), `StorageDriverRegistryInterface` (register/has/get), plus two
  optional capability interfaces `NativeSignedUrlProviderInterface` and `StorageHealthCheckInterface`
  that factories MAY implement.
- **Registry** (`src/Storage/StorageDriverRegistry.php`): in-memory driver -> factory map with a
  `withBuiltIns()` static constructor. Last-registered-wins per driver; logs/debug-reports overrides
  when a logger is available.
- **Built-in factories** (`src/Storage/Drivers/`): `LocalStorageDriverFactory`,
  `MemoryStorageDriverFactory` -- the ONLY factories in core.
- **Exception** (`src/Storage/Exceptions/`): `UnsupportedStorageDriverException` with a
  `forDriver($driver)` factory carrying a core-owned driver -> package suggestion map (so `s3`/`gcs`/
  `azure` produce a pointed "install glueful/storage-X" message even though their factories live in
  Plan B packs).
- **StorageManager**: constructor takes a nullable `StorageDriverRegistryInterface` defaulting to
  `StorageDriverRegistry::withBuiltIns()` (BC for `new StorageManager($config, $pathGuard)`);
  `createDisk()` resolves through the registry and throws `UnsupportedStorageDriverException` when
  `!has()`; `diskExists()` delegates to factory `available()`; `putStream()` uses
  `features()['supports_atomic_move']` to choose temp+move vs direct write; a `drivers()` accessor
  exposes the registry to collaborators (`FlysystemStorage`, `UploadController`). The three cloud
  factory methods are **removed** from core.
- **StorageProvider**: binds the registry; the `StorageManager` factory closure builds the registry
  via `withBuiltIns()` then layers any container-tagged `storage.driver_factory` services on top
  (last wins), and passes it into `StorageManager`. Keeps the `storage` alias.
- **FlysystemStorage**: deletes `isCloudDisk()` and delegates stream writes to
  `StorageManager::putStream()`; `getSignedUrl()` asks the factory via
  `NativeSignedUrlProviderInterface` then falls back to `getUrl()` on `null` or provider errors.
- **storage:test command** (`src/Console/Commands/Storage/StorageTestCommand.php`): read-only by
  default (registered? `available()`? non-mutating liveness via health-check capability); `--write`
  opts into a write/read/delete smoke test; never prints secrets.
- **Native-URL exposure** (`UploadController` + `config/uploads.php`): additive, optional, default-off,
  per-disk, visibility-scoped `native_url` field, always falling back to the app-signed `/blobs/{uuid}`.

## Tech Stack

- PHP 8.3+, `league/flysystem` v3 (`FilesystemOperator`, `Filesystem`, `LocalFilesystemAdapter`,
  `InMemoryFilesystemAdapter`).
- Glueful DI container: `FactoryDefinition`, `AliasDefinition`, `TaggedIteratorDefinition`,
  `BaseServiceProvider::tag()`, `TagCollector`.
- PHPUnit 10. Library-style tests extend `PHPUnit\Framework\TestCase`; container tests use
  `ApplicationContext::forTesting(dirname(__DIR__, N))` + `new Container($provider->defs())`.
- Symfony Console (`#[AsCommand]`, `BaseCommand`, `InputOption`).

---

## File Structure

```text
src/Storage/
  Contracts/
    StorageDriverFactoryInterface.php        (new)
    StorageDriverRegistryInterface.php       (new)
    NativeSignedUrlProviderInterface.php     (new)
    StorageHealthCheckInterface.php          (new)
  Drivers/
    LocalStorageDriverFactory.php            (new)
    MemoryStorageDriverFactory.php           (new)
  Exceptions/
    UnsupportedStorageDriverException.php     (new)
    StorageException.php                      (existing)
  StorageDriverRegistry.php                  (new)
  StorageManager.php                         (modified: registry-based, cloud factories removed, drivers() accessor added)
  PathGuard.php                              (existing)
  Support/UrlGenerator.php                   (existing)

src/Uploader/Storage/
  FlysystemStorage.php                       (modified: manager write delegation + native-signer seam)

src/Container/Providers/
  StorageProvider.php                        (modified: bind registry + tagged factories)

src/Console/Commands/Storage/
  StorageTestCommand.php                     (new)

src/Controllers/
  UploadController.php                       (modified: optional native_url field)

config/
  storage.php                                (modified: provider-pack comments; s3 stub disk commented out)
  uploads.php                                (modified: native_url policy block)

tests/
  Unit/Storage/Contracts/StorageDriverFactoryInterfaceTest.php   (new)
  Unit/Storage/Contracts/StorageDriverRegistryInterfaceTest.php  (new)
  Unit/Storage/Contracts/CapabilityInterfacesTest.php            (new)
  Unit/Storage/StorageDriverRegistryTest.php                 (new)
  Unit/Storage/UnsupportedStorageDriverExceptionTest.php     (new)
  Unit/Storage/Drivers/LocalStorageDriverFactoryTest.php     (new)
  Unit/Storage/Drivers/MemoryStorageDriverFactoryTest.php    (new)
  Unit/Storage/StorageManagerRegistryTest.php                (new)
  Unit/Container/Providers/StorageProviderRegistryTest.php   (new)
  Integration/Storage/FlysystemStorageTest.php               (modified: add atomic-move + native-signer cases)
  Unit/Console/Commands/Storage/StorageTestCommandTest.php   (new)
  Unit/Controllers/UploadControllerNativeUrlTest.php         (new)
  Unit/Uploader/FileUploaderNoMediaTest.php                  (existing; effective-disk regression gate, no changes)

CHANGELOG.md                                 (modified)
```

---

## Phase 1 -- Contracts and exception

### Task 1.1 -- StorageDriverFactoryInterface

**Files:**
- Create: `src/Storage/Contracts/StorageDriverFactoryInterface.php`
- Create: `tests/Unit/Storage/Contracts/StorageDriverFactoryInterfaceTest.php` (asserts the interface exists/loads and exposes the contract methods; the memory-factory test that exercises the contract end-to-end is created in Task 2.2)

Steps:

- [ ] Write a failing interface-contract test `tests/Unit/Storage/Contracts/StorageDriverFactoryInterfaceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Storage\Contracts;

use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use PHPUnit\Framework\TestCase;

final class StorageDriverFactoryInterfaceTest extends TestCase
{
    public function testContractExposesIdentityConstructionAvailabilityAndFeatures(): void
    {
        $this->assertTrue(interface_exists(StorageDriverFactoryInterface::class));

        $methods = ['driver', 'create', 'available', 'features'];
        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists(StorageDriverFactoryInterface::class, $method),
                "Missing contract method: {$method}"
            );
        }
    }
}
```

- [ ] Run `vendor/bin/phpunit --filter=StorageDriverFactoryInterfaceTest` -- expect FAIL (interface does not exist).

- [ ] Create `src/Storage/Contracts/StorageDriverFactoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Storage\Contracts;

use League\Flysystem\FilesystemOperator;

/**
 * Constructs a Flysystem disk for a single storage driver.
 *
 * Identity, construction, availability and feature metadata only. Provider
 * behavior (native signed URLs, health checks) lives behind the optional
 * capability interfaces in this namespace -- never on this contract.
 */
interface StorageDriverFactoryInterface
{
    /**
     * The driver name this factory handles (e.g. "local", "s3").
     */
    public function driver(): string;

    /**
     * Build a Flysystem operator from disk config (storage.disks.{name}).
     *
     * @param array<string, mixed> $config
     */
    public function create(array $config): FilesystemOperator;

    /**
     * True when required optional classes/config are available. Never throws.
     *
     * @param array<string, mixed> $config
     */
    public function available(array $config): bool;

    /**
     * Feature metadata. Keys default as documented when absent:
     *   supports_atomic_move        => true
     *   supports_native_signed_urls => false
     *   cloud                       => false
     *
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

- [ ] Run `vendor/bin/phpunit --filter=StorageDriverFactoryInterfaceTest` -- expect PASS.
- [ ] Commit: "feat(storage): add StorageDriverFactoryInterface contract".

### Task 1.2 -- StorageDriverRegistryInterface

**Files:**
- Create: `src/Storage/Contracts/StorageDriverRegistryInterface.php`
- Create: `tests/Unit/Storage/Contracts/StorageDriverRegistryInterfaceTest.php`

Steps:

- [ ] Write failing test `tests/Unit/Storage/Contracts/StorageDriverRegistryInterfaceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Storage\Contracts;

use Glueful\Storage\Contracts\StorageDriverRegistryInterface;
use PHPUnit\Framework\TestCase;

final class StorageDriverRegistryInterfaceTest extends TestCase
{
    public function testContractExposesRegisterHasGet(): void
    {
        $this->assertTrue(interface_exists(StorageDriverRegistryInterface::class));
        foreach (['register', 'has', 'get'] as $method) {
            $this->assertTrue(
                method_exists(StorageDriverRegistryInterface::class, $method),
                "Missing contract method: {$method}"
            );
        }
    }
}
```

- [ ] Run `vendor/bin/phpunit --filter=StorageDriverRegistryInterfaceTest` -- expect FAIL.

- [ ] Create `src/Storage/Contracts/StorageDriverRegistryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Storage\Contracts;

interface StorageDriverRegistryInterface
{
    public function register(string $driver, StorageDriverFactoryInterface $factory): void;

    public function has(string $driver): bool;

    /**
     * @throws \Glueful\Storage\Exceptions\UnsupportedStorageDriverException
     */
    public function get(string $driver): StorageDriverFactoryInterface;
}
```

- [ ] Run `vendor/bin/phpunit --filter=StorageDriverRegistryInterfaceTest` -- expect PASS.
- [ ] Commit: "feat(storage): add StorageDriverRegistryInterface contract".

### Task 1.3 -- Optional capability interfaces

**Files:**
- Create: `src/Storage/Contracts/NativeSignedUrlProviderInterface.php`
- Create: `src/Storage/Contracts/StorageHealthCheckInterface.php`
- Create: `tests/Unit/Storage/Contracts/CapabilityInterfacesTest.php`

Steps:

- [ ] Write failing test `tests/Unit/Storage/Contracts/CapabilityInterfacesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Storage\Contracts;

use Glueful\Storage\Contracts\NativeSignedUrlProviderInterface;
use Glueful\Storage\Contracts\StorageHealthCheckInterface;
use PHPUnit\Framework\TestCase;

final class CapabilityInterfacesTest extends TestCase
{
    public function testNativeSignedUrlProviderContract(): void
    {
        $this->assertTrue(interface_exists(NativeSignedUrlProviderInterface::class));
        $this->assertTrue(method_exists(NativeSignedUrlProviderInterface::class, 'temporaryUrl'));
    }

    public function testHealthCheckContract(): void
    {
        $this->assertTrue(interface_exists(StorageHealthCheckInterface::class));
        $this->assertTrue(method_exists(StorageHealthCheckInterface::class, 'check'));
    }
}
```

- [ ] Run `vendor/bin/phpunit --filter=CapabilityInterfacesTest` -- expect FAIL.

- [ ] Create `src/Storage/Contracts/NativeSignedUrlProviderInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Storage\Contracts;

/**
 * Optional capability: a factory whose provider can mint a native object-store
 * temporary (presigned) URL. Returns null when it cannot sign for this path.
 */
interface NativeSignedUrlProviderInterface
{
    /**
     * @param array<string, mixed> $diskConfig
     */
    public function temporaryUrl(string $path, int $ttl, array $diskConfig): ?string;
}
```

- [ ] Create `src/Storage/Contracts/StorageHealthCheckInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Storage\Contracts;

/**
 * Optional capability: a factory that can run a non-mutating liveness probe
 * for a disk (e.g. HEAD/list). Must never print or return secrets.
 */
interface StorageHealthCheckInterface
{
    /**
     * @param array<string, mixed> $diskConfig
     * @return array{ok: bool, message: string, details?: array<string, mixed>}
     */
    public function check(string $disk, array $diskConfig): array;
}
```

- [ ] Run `vendor/bin/phpunit --filter=CapabilityInterfacesTest` -- expect PASS.
- [ ] Commit: "feat(storage): add native-signed-url and health-check capability contracts".

### Task 1.4 -- UnsupportedStorageDriverException

**Files:**
- Create: `src/Storage/Exceptions/UnsupportedStorageDriverException.php`
- Create: `tests/Unit/Storage/UnsupportedStorageDriverExceptionTest.php`

Steps:

- [ ] Write failing test `tests/Unit/Storage/UnsupportedStorageDriverExceptionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Storage;

use Glueful\Storage\Exceptions\UnsupportedStorageDriverException;
use PHPUnit\Framework\TestCase;

final class UnsupportedStorageDriverExceptionTest extends TestCase
{
    public function testForDriverNamesTheFirstPartyPackage(): void
    {
        $e = UnsupportedStorageDriverException::forDriver('s3');
        $this->assertInstanceOf(\InvalidArgumentException::class, $e);
        $this->assertStringContainsString("'s3'", $e->getMessage());
        $this->assertStringContainsString('composer require glueful/storage-s3', $e->getMessage());
    }

    public function testGcsAndAzureSuggestions(): void
    {
        $this->assertStringContainsString(
            'glueful/storage-gcs',
            UnsupportedStorageDriverException::forDriver('gcs')->getMessage()
        );
        $this->assertStringContainsString(
            'glueful/storage-azure',
            UnsupportedStorageDriverException::forDriver('azure')->getMessage()
        );
    }

    public function testUnknownDriverHasNoPackageSuggestion(): void
    {
        $msg = UnsupportedStorageDriverException::forDriver('frobnicate')->getMessage();
        $this->assertStringContainsString("'frobnicate'", $msg);
        $this->assertStringNotContainsString('composer require', $msg);
    }

    public function testDriverAccessor(): void
    {
        $this->assertSame('s3', UnsupportedStorageDriverException::forDriver('s3')->driver());
    }
}
```

- [ ] Run `vendor/bin/phpunit --filter=UnsupportedStorageDriverExceptionTest` -- expect FAIL.

- [ ] Create `src/Storage/Exceptions/UnsupportedStorageDriverException.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Storage\Exceptions;

/**
 * Thrown by StorageManager::createDisk()/diskExists() when a disk's driver has
 * no registered factory. For first-party drivers whose factories now live in
 * provider packs, the message names the package to install. The driver ->
 * package suggestion map is core-owned so the hint works even though the
 * factories do not ship in core.
 */
final class UnsupportedStorageDriverException extends \InvalidArgumentException
{
    /** @var array<string, string> */
    private const PACKAGE_SUGGESTIONS = [
        's3' => 'glueful/storage-s3',
        'gcs' => 'glueful/storage-gcs',
        'azure' => 'glueful/storage-azure',
    ];

    private string $driver;

    private function __construct(string $driver, string $message)
    {
        parent::__construct($message);
        $this->driver = $driver;
    }

    public static function forDriver(string $driver): self
    {
        $message = "Unsupported disk driver '{$driver}'.";

        $package = self::PACKAGE_SUGGESTIONS[$driver] ?? null;
        if ($package !== null) {
            $message .= " Install it with: composer require {$package}";
        }

        return new self($driver, $message);
    }

    public function driver(): string
    {
        return $this->driver;
    }
}
```

- [ ] Run `vendor/bin/phpunit --filter=UnsupportedStorageDriverExceptionTest` -- expect PASS.
- [ ] Commit: "feat(storage): add UnsupportedStorageDriverException with package suggestions".

---

## Phase 2 -- Registry and built-in factories

### Task 2.1 -- LocalStorageDriverFactory

**Files:**
- Create: `src/Storage/Drivers/LocalStorageDriverFactory.php`
- Create: `tests/Unit/Storage/Drivers/LocalStorageDriverFactoryTest.php`

Steps:

- [ ] Write failing test `tests/Unit/Storage/Drivers/LocalStorageDriverFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Storage\Drivers;

use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use Glueful\Storage\Drivers\LocalStorageDriverFactory;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;

final class LocalStorageDriverFactoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/glueful-local-' . uniqid('', true);
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        @array_map('unlink', glob($this->root . '/*') ?: []);
        @rmdir($this->root);
    }

    public function testDriverName(): void
    {
        $this->assertSame('local', (new LocalStorageDriverFactory())->driver());
    }

    public function testImplementsContract(): void
    {
        $this->assertInstanceOf(StorageDriverFactoryInterface::class, new LocalStorageDriverFactory());
    }

    public function testCreateReturnsWorkingOperator(): void
    {
        $fs = (new LocalStorageDriverFactory())->create(['root' => $this->root, 'visibility' => 'private']);
        $this->assertInstanceOf(FilesystemOperator::class, $fs);
        $fs->write('a.txt', 'hello');
        $this->assertSame('hello', $fs->read('a.txt'));
    }

    public function testAlwaysAvailable(): void
    {
        $this->assertTrue((new LocalStorageDriverFactory())->available(['root' => $this->root]));
    }

    public function testSupportsAtomicMove(): void
    {
        $this->assertSame(true, (new LocalStorageDriverFactory())->features([])['supports_atomic_move']);
        $this->assertSame(false, (new LocalStorageDriverFactory())->features([])['cloud']);
    }
}
```

- [ ] Run `vendor/bin/phpunit --filter=LocalStorageDriverFactoryTest` -- expect FAIL.

- [ ] Create `src/Storage/Drivers/LocalStorageDriverFactory.php` (lift the `local` arm out of the old `StorageManager::createDisk()` match verbatim):

```php
<?php

declare(strict_types=1);

namespace Glueful\Storage\Drivers;

use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;

final class LocalStorageDriverFactory implements StorageDriverFactoryInterface
{
    public function driver(): string
    {
        return 'local';
    }

    public function create(array $config): FilesystemOperator
    {
        return new Filesystem(new LocalFilesystemAdapter(
            (string) ($config['root'] ?? ''),
            PortableVisibilityConverter::fromArray([
                'file' => ['public' => 0644, 'private' => 0600],
                'dir' => ['public' => 0755, 'private' => 0700],
            ], (string) ($config['visibility'] ?? 'private'))
        ));
    }

    public function available(array $config): bool
    {
        return true;
    }

    public function features(array $config): array
    {
        return [
            'supports_atomic_move' => true,
            'supports_native_signed_urls' => false,
            'cloud' => false,
        ];
    }
}
```

- [ ] Run `vendor/bin/phpunit --filter=LocalStorageDriverFactoryTest` -- expect PASS.
- [ ] Commit: "feat(storage): add LocalStorageDriverFactory built-in".

### Task 2.2 -- MemoryStorageDriverFactory

**Files:**
- Create: `src/Storage/Drivers/MemoryStorageDriverFactory.php`
- Create: `tests/Unit/Storage/Drivers/MemoryStorageDriverFactoryTest.php`

Steps:

- [ ] Write failing test `tests/Unit/Storage/Drivers/MemoryStorageDriverFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Storage\Drivers;

use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use Glueful\Storage\Drivers\MemoryStorageDriverFactory;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;

final class MemoryStorageDriverFactoryTest extends TestCase
{
    public function testDriverName(): void
    {
        $this->assertSame('memory', (new MemoryStorageDriverFactory())->driver());
    }

    public function testImplementsContract(): void
    {
        $this->assertInstanceOf(StorageDriverFactoryInterface::class, new MemoryStorageDriverFactory());
    }

    public function testCreateReturnsWorkingOperator(): void
    {
        $fs = (new MemoryStorageDriverFactory())->create([]);
        $this->assertInstanceOf(FilesystemOperator::class, $fs);
        $fs->write('a.txt', 'hello');
        $this->assertSame('hello', $fs->read('a.txt'));
    }

    public function testAlwaysAvailableAndAtomic(): void
    {
        $f = new MemoryStorageDriverFactory();
        $this->assertTrue($f->available([]));
        $this->assertSame(true, $f->features([])['supports_atomic_move']);
    }
}
```

- [ ] Run `vendor/bin/phpunit --filter=MemoryStorageDriverFactoryTest` -- expect FAIL.

- [ ] Create `src/Storage/Drivers/MemoryStorageDriverFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Storage\Drivers;

use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

final class MemoryStorageDriverFactory implements StorageDriverFactoryInterface
{
    public function driver(): string
    {
        return 'memory';
    }

    public function create(array $config): FilesystemOperator
    {
        return new Filesystem(new InMemoryFilesystemAdapter());
    }

    public function available(array $config): bool
    {
        return true;
    }

    public function features(array $config): array
    {
        return [
            'supports_atomic_move' => true,
            'supports_native_signed_urls' => false,
            'cloud' => false,
        ];
    }
}
```

- [ ] Run `vendor/bin/phpunit --filter=MemoryStorageDriverFactoryTest` -- expect PASS.
- [ ] Commit: "feat(storage): add MemoryStorageDriverFactory built-in".

### Task 2.3 -- StorageDriverRegistry (+ withBuiltIns)

**Files:**
- Create: `src/Storage/StorageDriverRegistry.php`
- Create: `tests/Unit/Storage/StorageDriverRegistryTest.php`

Steps:

- [ ] Write failing test `tests/Unit/Storage/StorageDriverRegistryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Storage;

use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use Glueful\Storage\Contracts\StorageDriverRegistryInterface;
use Glueful\Storage\Drivers\LocalStorageDriverFactory;
use Glueful\Storage\Drivers\MemoryStorageDriverFactory;
use Glueful\Storage\Exceptions\UnsupportedStorageDriverException;
use Glueful\Storage\StorageDriverRegistry;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class StorageDriverRegistryTest extends TestCase
{
    public function testImplementsContract(): void
    {
        $this->assertInstanceOf(StorageDriverRegistryInterface::class, new StorageDriverRegistry());
    }

    public function testWithBuiltInsRegistersLocalAndMemoryOnly(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns();

        $this->assertTrue($registry->has('local'));
        $this->assertTrue($registry->has('memory'));
        $this->assertFalse($registry->has('s3'));
        $this->assertInstanceOf(LocalStorageDriverFactory::class, $registry->get('local'));
        $this->assertInstanceOf(MemoryStorageDriverFactory::class, $registry->get('memory'));
    }

    public function testGetUnknownDriverThrows(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns();
        $this->expectException(UnsupportedStorageDriverException::class);
        $registry->get('s3');
    }

    public function testRegisterOverwritesSameDriverLastWins(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns();
        $fake = $this->fakeFactory('memory');

        $registry->register('memory', $fake);

        $this->assertSame($fake, $registry->get('memory'));
    }

    public function testRegisterNewDriverIsResolvable(): void
    {
        $registry = new StorageDriverRegistry();
        $fake = $this->fakeFactory('fake');
        $registry->register('fake', $fake);

        $this->assertTrue($registry->has('fake'));
        $this->assertSame($fake, $registry->get('fake'));
    }

    private function fakeFactory(string $driver): StorageDriverFactoryInterface
    {
        return new class ($driver) implements StorageDriverFactoryInterface {
            public function __construct(private string $name)
            {
            }
            public function driver(): string
            {
                return $this->name;
            }
            public function create(array $config): FilesystemOperator
            {
                return new Filesystem(new InMemoryFilesystemAdapter());
            }
            public function available(array $config): bool
            {
                return true;
            }
            public function features(array $config): array
            {
                return ['supports_atomic_move' => false, 'cloud' => true];
            }
        };
    }
}
```

- [ ] Run `vendor/bin/phpunit --filter=StorageDriverRegistryTest` -- expect FAIL.

- [ ] Create `src/Storage/StorageDriverRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Storage;

use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use Glueful\Storage\Contracts\StorageDriverRegistryInterface;
use Glueful\Storage\Drivers\LocalStorageDriverFactory;
use Glueful\Storage\Drivers\MemoryStorageDriverFactory;
use Glueful\Storage\Exceptions\UnsupportedStorageDriverException;
use Psr\Log\LoggerInterface;

final class StorageDriverRegistry implements StorageDriverRegistryInterface
{
    /** @var array<string, StorageDriverFactoryInterface> */
    private array $factories = [];

    public function __construct(private ?LoggerInterface $logger = null)
    {
    }

    /**
     * Registry seeded with the dependency-free reference factories only.
     * Cloud drivers (s3/gcs/azure) ship as first-party provider packs.
     */
    public static function withBuiltIns(?LoggerInterface $logger = null): self
    {
        $registry = new self($logger);
        $registry->register('local', new LocalStorageDriverFactory());
        $registry->register('memory', new MemoryStorageDriverFactory());

        return $registry;
    }

    public function register(string $driver, StorageDriverFactoryInterface $factory): void
    {
        if (isset($this->factories[$driver]) && $this->logger !== null) {
            $this->logger->debug('Storage driver factory replaced', [
                'driver' => $driver,
                'previous' => $this->factories[$driver]::class,
                'replacement' => $factory::class,
            ]);
        }

        $this->factories[$driver] = $factory;
    }

    public function has(string $driver): bool
    {
        return isset($this->factories[$driver]);
    }

    public function get(string $driver): StorageDriverFactoryInterface
    {
        if (!isset($this->factories[$driver])) {
            throw UnsupportedStorageDriverException::forDriver($driver);
        }

        return $this->factories[$driver];
    }
}
```

- [ ] Run `vendor/bin/phpunit --filter=StorageDriverRegistryTest` -- expect PASS.
- [ ] Commit: "feat(storage): add StorageDriverRegistry with built-in reference factories".

---

## Phase 3 -- Rewire StorageManager

### Task 3.1 -- Registry-based createDisk/diskExists + drivers() accessor; remove cloud factories

**Files:**
- Modify: `src/Storage/StorageManager.php`
- Create: `tests/Unit/Storage/StorageManagerRegistryTest.php`

Steps:

- [ ] Write failing test `tests/Unit/Storage/StorageManagerRegistryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Storage;

use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use Glueful\Storage\Exceptions\UnsupportedStorageDriverException;
use Glueful\Storage\PathGuard;
use Glueful\Storage\StorageDriverRegistry;
use Glueful\Storage\StorageManager;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class StorageManagerRegistryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/glueful-sm-' . uniqid('', true);
        mkdir($this->root, 0755, true);
    }

    public function testCreatesLocalAndMemoryThroughDefaultBuiltIns(): void
    {
        $config = [
            'default' => 'local',
            'disks' => [
                'local' => ['driver' => 'local', 'root' => $this->root],
                'mem' => ['driver' => 'memory'],
            ],
        ];
        $sm = new StorageManager($config, new PathGuard()); // nullable registry -> withBuiltIns()

        $this->assertInstanceOf(FilesystemOperator::class, $sm->disk('local'));
        $this->assertInstanceOf(FilesystemOperator::class, $sm->disk('mem'));
    }

    public function testUnknownDriverThrowsUnsupportedStorageDriverException(): void
    {
        $config = ['default' => 's3', 'disks' => ['s3' => ['driver' => 's3', 'bucket' => 'b']]];
        $sm = new StorageManager($config, new PathGuard());

        $this->expectException(UnsupportedStorageDriverException::class);
        $this->expectExceptionMessageMatches('/composer require glueful\/storage-s3/');
        $sm->disk('s3');
    }

    public function testDiskExistsDelegatesToFactoryAvailable(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns();
        $registry->register('flaky', $this->factory('flaky', available: false));

        $config = [
            'default' => 'mem',
            'disks' => [
                'mem' => ['driver' => 'memory'],
                'gone' => ['driver' => 'flaky'],
                'unconfigured-driver' => ['driver' => 'nope'],
            ],
        ];
        $sm = new StorageManager($config, new PathGuard(), $registry);

        $this->assertTrue($sm->diskExists('mem'));        // available() === true
        $this->assertFalse($sm->diskExists('gone'));      // available() === false
        $this->assertFalse($sm->diskExists('nope-disk')); // not configured at all
        $this->assertFalse($sm->diskExists('unconfigured-driver')); // driver not registered
    }

    public function testExtensionFactoryResolvesADisk(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns();
        $registry->register('fake', $this->factory('fake'));

        $config = ['default' => 'f', 'disks' => ['f' => ['driver' => 'fake']]];
        $sm = new StorageManager($config, new PathGuard(), $registry);

        $this->assertInstanceOf(FilesystemOperator::class, $sm->disk('f'));
    }

    public function testDriversAccessorExposesTheRegistry(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns();
        $config = ['default' => 'mem', 'disks' => ['mem' => ['driver' => 'memory']]];
        $sm = new StorageManager($config, new PathGuard(), $registry);

        $this->assertSame($registry, $sm->drivers());

        // Default construction exposes the built-ins registry.
        $smDefault = new StorageManager($config, new PathGuard());
        $this->assertTrue($smDefault->drivers()->has('local'));
        $this->assertTrue($smDefault->drivers()->has('memory'));
    }

    private function factory(string $driver, bool $available = true): StorageDriverFactoryInterface
    {
        return new class ($driver, $available) implements StorageDriverFactoryInterface {
            public function __construct(private string $name, private bool $ok)
            {
            }
            public function driver(): string
            {
                return $this->name;
            }
            public function create(array $config): FilesystemOperator
            {
                return new Filesystem(new InMemoryFilesystemAdapter());
            }
            public function available(array $config): bool
            {
                return $this->ok;
            }
            public function features(array $config): array
            {
                return [];
            }
        };
    }
}
```

- [ ] Run `vendor/bin/phpunit --filter=StorageManagerRegistryTest` -- expect FAIL. (Note: PHP
      silently ignores the extra constructor argument, so `new StorageManager($config, $pathGuard,
      $registry)` does not error -- the injected registry is simply unused. The real first failures
      are: the fake/extension drivers (`flaky`, `fake`) cannot resolve because the old `match` does
      not know them; unknown drivers throw the old generic exception instead of
      `UnsupportedStorageDriverException`; and `drivers()` does not exist yet.)

- [ ] Modify `src/Storage/StorageManager.php`:
  - Add imports: `use Glueful\Storage\Contracts\StorageDriverRegistryInterface;` and
    `use Glueful\Storage\Exceptions\UnsupportedStorageDriverException;`
  - Drop now-unused imports `Filesystem`, `LocalFilesystemAdapter`, `PortableVisibilityConverter`
    (their use moved into the factories).
  - Add a `private StorageDriverRegistryInterface $drivers;` property.
  - Replace the constructor:

```php
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        array $config,
        PathGuard $pathGuard,
        ?StorageDriverRegistryInterface $drivers = null
    ) {
        $this->config = $config;
        $this->pathGuard = $pathGuard;
        $this->drivers = $drivers ?? StorageDriverRegistry::withBuiltIns();
    }
```

  - Replace `createDisk()`:

```php
    private function createDisk(string $name): FilesystemOperator
    {
        if (!isset($this->config['disks'][$name])) {
            throw new \InvalidArgumentException("Disk '{$name}' is not configured");
        }

        /** @var array<string, mixed> $config */
        $config = $this->config['disks'][$name];
        $driver = (string) ($config['driver'] ?? '');

        if (!$this->drivers->has($driver)) {
            throw UnsupportedStorageDriverException::forDriver($driver);
        }

        return $this->drivers->get($driver)->create($config);
    }
```

  - Replace `diskExists()`:

```php
    public function diskExists(string $name): bool
    {
        if (!isset($this->config['disks'][$name])) {
            return false;
        }

        /** @var array<string, mixed> $config */
        $config = $this->config['disks'][$name];
        $driver = (string) ($config['driver'] ?? '');

        if (!$this->drivers->has($driver)) {
            return false;
        }

        return $this->drivers->get($driver)->available($config);
    }
```

  - Add the registry accessor (consumed by `FlysystemStorage` in Task 5.1 and the native-URL
    wiring in Task 7.1):

```php
    /**
     * The driver registry this manager resolves disks through. Exposed so
     * collaborators (FlysystemStorage, UploadController) can consult factory
     * features/capabilities without a constructor-signature break.
     */
    public function drivers(): StorageDriverRegistryInterface
    {
        return $this->drivers;
    }
```

  - Replace `putStream()` so the public manager API honors the same atomic-move capability as
    uploader storage. This is the central rule: callers should not need to know whether a disk is
    local/atomic or object-store/non-atomic. Non-atomic drivers write directly; atomic drivers keep
    the existing temp+move path.

```php
    /**
     * Stream write for large files.
     *
     * Atomic-capable drivers use temp+move for crash safety. Non-atomic drivers
     * (object stores such as S3/GCS/Azure) write directly because temp+move maps
     * to provider-side copy/delete operations that may fail or be non-atomic.
     *
     * @param resource $stream
     */
    public function putStream(string $path, $stream, ?string $disk = null): void
    {
        $path = $this->pathGuard->validate($path);

        try {
            if (!$this->supportsAtomicMove($disk)) {
                $this->disk($disk)->writeStream($path, $stream);
                return;
            }

            $temp = $this->generateTempPath($path);
            try {
                $this->disk($disk)->writeStream($temp, $stream);
                $this->disk($disk)->move($temp, $path);
            } finally {
                try {
                    $this->disk($disk)->delete($temp);
                } catch (\Throwable) {
                    // Ignore - temp file may have been moved or never written.
                }
            }
        } catch (FilesystemException $e) {
            throw StorageException::fromFlysystem($e, $path);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function supportsAtomicMove(?string $disk = null): bool
    {
        $name = $disk ?? (string) ($this->config['default'] ?? '');
        /** @var array<string, mixed> $config */
        $config = (array) ($this->config['disks'][$name] ?? []);
        $driver = (string) ($config['driver'] ?? '');

        if (!$this->drivers->has($driver)) {
            return true;
        }

        return ($this->drivers->get($driver)->features($config)['supports_atomic_move'] ?? true) === true;
    }
```

  - Add a regression to `StorageManagerRegistryTest` using a fake non-atomic driver whose returned
    `FilesystemOperator::move()` throws if called. Assert `putStream()` succeeds and the fake saw a
    direct `writeStream()` to the final path. This proves direct `StorageManager` callers get the
    same non-atomic protection as `FlysystemStorage`.
  - **Delete** the three private methods `createS3Filesystem()`, `createAzureFilesystem()`,
    `createGcsFilesystem()` in their entirety. Keep `generateTempPath()`, `putJson()`, `getJson()`,
    `listContents()` untouched.

- [ ] Run `vendor/bin/phpunit --filter=StorageManagerRegistryTest` -- expect PASS.
- [ ] Run `vendor/bin/phpunit tests/Integration/Storage/FlysystemStorageTest.php` -- expect PASS
      (memory disk still resolves; existing behavior preserved).
- [ ] Run `composer run analyse:changed` -- expect PASS (no references to the removed methods remain).
- [ ] Commit: "refactor(storage): route StorageManager through the driver registry; drop core cloud factories; expose drivers() accessor".

---

## Phase 4 -- Rewire StorageProvider

### Task 4.1 -- Bind registry, register built-ins then tagged factories, pass into StorageManager

**Files:**
- Modify: `src/Container/Providers/StorageProvider.php`
- Create: `tests/Unit/Container/Providers/StorageProviderRegistryTest.php`

Steps:

- [ ] Write failing test `tests/Unit/Container/Providers/StorageProviderRegistryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Container\Providers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Container\Providers\StorageProvider;
use Glueful\Container\Providers\TagCollector;
use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use Glueful\Storage\Contracts\StorageDriverRegistryInterface;
use Glueful\Storage\StorageManager;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class StorageProviderRegistryTest extends TestCase
{
    public function testRegistryIsBoundWithBuiltIns(): void
    {
        $context = ApplicationContext::forTesting(dirname(__DIR__, 4));
        $provider = new StorageProvider(new TagCollector(), $context);
        $container = new Container($provider->defs());

        $registry = $container->get(StorageDriverRegistryInterface::class);
        $this->assertInstanceOf(StorageDriverRegistryInterface::class, $registry);
        $this->assertTrue($registry->has('local'));
        $this->assertTrue($registry->has('memory'));
    }

    public function testTaggedFactoryIsCollectedAndOverridesBuiltIn(): void
    {
        $context = ApplicationContext::forTesting(dirname(__DIR__, 4));
        $provider = new StorageProvider(new TagCollector(), $context);

        $defs = $provider->defs();
        // Simulate an extension contributing a factory under the tagged-iterator id.
        $fake = $this->fakeFactory('memory');
        $defs['storage.driver_factory'] = new ValueDefinition('storage.driver_factory', [$fake]);

        $container = new Container($defs);

        $registry = $container->get(StorageDriverRegistryInterface::class);
        $this->assertSame($fake, $registry->get('memory')); // last-registered (tagged) wins
        $this->assertTrue($registry->has('local'));         // built-in still present
    }

    public function testHigherPriorityTaggedFactoryWinsSameDriverCollision(): void
    {
        $context = ApplicationContext::forTesting(dirname(__DIR__, 4));
        $provider = new StorageProvider(new TagCollector(), $context);

        $low = $this->fakeFactory('memory');
        $high = $this->fakeFactory('memory');

        $defs = $provider->defs();
        // Simulate ContainerFactory's tagged iterator output: it sorts priority
        // DESC, so StorageProvider must register in reverse order for higher
        // priority to win under the registry's last-wins semantics.
        $defs['storage.driver_factory'] = new ValueDefinition('storage.driver_factory', [$high, $low]);

        $container = new Container($defs);

        $registry = $container->get(StorageDriverRegistryInterface::class);
        $this->assertSame($high, $registry->get('memory'));
    }

    public function testStorageManagerReceivesTheRegistry(): void
    {
        $context = ApplicationContext::forTesting(dirname(__DIR__, 4));
        $provider = new StorageProvider(new TagCollector(), $context);

        $defs = $provider->defs();
        $fake = $this->fakeFactory('fake');
        $defs['storage.driver_factory'] = new ValueDefinition('storage.driver_factory', [$fake]);
        $container = new Container($defs);

        /** @var StorageManager $sm */
        $sm = $container->get(StorageManager::class);
        // A disk using the tagged 'fake' driver must resolve, proving the manager
        // got the same registry the provider populated.
        $reflection = new \ReflectionObject($sm);
        $prop = $reflection->getProperty('drivers');
        $prop->setAccessible(true);
        $this->assertTrue($prop->getValue($sm)->has('fake'));
    }

    private function fakeFactory(string $driver): StorageDriverFactoryInterface
    {
        return new class ($driver) implements StorageDriverFactoryInterface {
            public function __construct(private string $name)
            {
            }
            public function driver(): string
            {
                return $this->name;
            }
            public function create(array $config): FilesystemOperator
            {
                return new Filesystem(new InMemoryFilesystemAdapter());
            }
            public function available(array $config): bool
            {
                return true;
            }
            public function features(array $config): array
            {
                return [];
            }
        };
    }
}
```

- [ ] Add a real ContainerFactory integration regression under `tests/Integration/Container/` using
      a fixture extension provider that declares a factory service via `services()` with
      `['tags' => ['storage.driver_factory']]`, then enables it through `serviceproviders.enabled`.
      Assert `ContainerFactory::create($context)` resolves `StorageDriverRegistryInterface` with the
      fixture driver and a configured disk using that driver resolves. This guards the actual
      extension DSL path instead of only a hand-built `ValueDefinition`.

- [ ] Run `vendor/bin/phpunit --filter=StorageProviderRegistryTest` -- expect FAIL.

- [ ] Modify `src/Container/Providers/StorageProvider.php`:
  - Bind the registry interface to a shared instance and build the `StorageManager` from it.
    Replace the existing `StorageManager` `FactoryDefinition` and add a registry binding before it:

```php
        // Storage driver registry: built-ins first, then any extension factories
        // tagged 'storage.driver_factory' (last registered wins per driver name).
        $defs[\Glueful\Storage\Contracts\StorageDriverRegistryInterface::class] = new FactoryDefinition(
            \Glueful\Storage\Contracts\StorageDriverRegistryInterface::class,
            function (\Psr\Container\ContainerInterface $c): \Glueful\Storage\Contracts\StorageDriverRegistryInterface {
                $logger = $c->has(\Psr\Log\LoggerInterface::class)
                    ? $c->get(\Psr\Log\LoggerInterface::class)
                    : null;

                $registry = \Glueful\Storage\StorageDriverRegistry::withBuiltIns($logger);

                // Extension factories arrive via the tagged iterator. Each is
                // registered under its own driver() name; built-ins registered
                // first so an extension factory can intentionally override.
                if ($c->has('storage.driver_factory')) {
                    /** @var iterable<\Glueful\Storage\Contracts\StorageDriverFactoryInterface> $factories */
                    $factories = $c->get('storage.driver_factory');
                    if ($factories instanceof \Traversable) {
                        $factories = iterator_to_array($factories);
                    }

                    // TaggedIteratorDefinition sorts priority DESC. The registry
                    // is last-wins, so reverse registration makes higher priority
                    // the final binding for a driver collision.
                    foreach (array_reverse((array) $factories) as $factory) {
                        $registry->register($factory->driver(), $factory);
                    }
                }

                return $registry;
            }
        );

        // StorageManager built from config/storage.php, wired to the registry.
        $defs[\Glueful\Storage\StorageManager::class] = new FactoryDefinition(
            \Glueful\Storage\StorageManager::class,
            function (\Psr\Container\ContainerInterface $c): \Glueful\Storage\StorageManager {
                /** @var array<string,mixed> $cfg */
                $cfg = (array) (\function_exists('config') ? \config($this->context, 'storage') : []);
                return new \Glueful\Storage\StorageManager(
                    $cfg,
                    new \Glueful\Storage\PathGuard(),
                    $c->get(\Glueful\Storage\Contracts\StorageDriverRegistryInterface::class)
                );
            }
        );
```

  - Leave the `PathGuard`, `UrlGenerator`, `storage` alias, `FileUploader`,
    `ImageSecurityValidator`, and `UploadController` definitions unchanged.

> NOTE (cross-plan contract): Plan B's provider packs register each `*StorageDriverFactory` as a
> container service tagged `storage.driver_factory` via the extension `services()` DSL `'tags'`
> key (e.g. `'tags' => ['storage.driver_factory']`, consumed by
> `ContainerFactory::applyDslTags()`) -- exactly the additive mechanism `console.commands` uses.
> Extension ServiceProviders do NOT have `$this->tag(...)`: that is a `BaseServiceProvider`
> (core container provider) method, so core-side providers (like StorageProvider itself) may use
> `$this->tag()`, but extensions must use the DSL `'tags'` key. This plan's StorageProvider only
> consumes that tagged-iterator id; it never names a pack.
>
> Ordering note: `TaggedIteratorDefinition` orders tagged services by priority DESC, then
> insertion order. `StorageProvider` intentionally registers that iterator in reverse order because
> the registry is last-wins; therefore a higher tag priority wins a same-driver collision. Packs
> should use the default priority unless they intentionally override another factory for the same
> driver.

- [ ] Run `vendor/bin/phpunit --filter=StorageProviderRegistryTest` -- expect PASS.
- [ ] Run `vendor/bin/phpunit tests/Unit/Container/Providers/StorageProviderImageValidatorTest.php tests/Unit/Container/Providers/StorageProviderFileUploaderMediaTest.php` -- expect PASS (no regression).
- [ ] Commit: "feat(storage): build driver registry in StorageProvider from tagged factories".

---

## Phase 5 -- Rewire FlysystemStorage

### Task 5.1 -- features()-driven atomic move + native signed URL seam

**Files:**
- Modify: `src/Uploader/Storage/FlysystemStorage.php`
- Modify: `tests/Integration/Storage/FlysystemStorageTest.php`

Steps:

- [ ] Add failing cases to `tests/Integration/Storage/FlysystemStorageTest.php`. The class only has
      `StorageManager $storage` / `UrlGenerator $urls` today; add a registry field so tests can inject
      fake factories, and add four cases:

```php
    // add imports at top of file:
    // use Glueful\Storage\Contracts\NativeSignedUrlProviderInterface;
    // use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
    // use Glueful\Storage\StorageDriverRegistry;
    // use League\Flysystem\Filesystem;
    // use League\Flysystem\FilesystemOperator;
    // use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

    public function testStoreUsesDirectWriteWhenAtomicMoveUnsupported(): void
    {
        // A non-atomic ('cloud-like') driver: store() delegates to
        // StorageManager::putStream(), which must choose direct writeStream.
        $registry = StorageDriverRegistry::withBuiltIns();
        $registry->register('cloudish', $this->nonAtomicFactory('cloudish'));

        $config = ['default' => 'c', 'disks' => ['c' => ['driver' => 'cloudish']]];
        $storage = new StorageManager($config, new \Glueful\Storage\PathGuard(), $registry);
        $urls = new UrlGenerator($config, new \Glueful\Storage\PathGuard());

        $fs = new FlysystemStorage($storage, $urls, 'c');

        $tmp = tmpfile();
        fwrite($tmp, 'cloud-bytes');
        $path = stream_get_meta_data($tmp)['uri'];

        $fs->store($path, 'x/y.txt');
        $this->assertTrue($fs->exists('x/y.txt'));
    }

    public function testStoreDefaultsToAtomicMoveWhenFeatureMissing(): void
    {
        // memory built-in has supports_atomic_move => true; temp+move path.
        $fs = new FlysystemStorage($this->storage, $this->urls, $this->disk);
        $tmp = tmpfile();
        fwrite($tmp, 'atomic');
        $path = stream_get_meta_data($tmp)['uri'];

        $fs->store($path, 'a/b.txt');
        $this->assertTrue($fs->exists('a/b.txt'));
    }

    public function testGetSignedUrlUsesNativeSignerWhenAvailable(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns();
        $registry->register('signing', $this->nativeSigningFactory('signing'));

        $config = ['default' => 's', 'disks' => ['s' => ['driver' => 'signing']]];
        $storage = new StorageManager($config, new \Glueful\Storage\PathGuard(), $registry);
        $urls = new UrlGenerator($config, new \Glueful\Storage\PathGuard());

        $fs = new FlysystemStorage($storage, $urls, 's');

        $this->assertSame('https://signed.example/obj.txt?ttl=120', $fs->getSignedUrl('obj.txt', 120));
    }

    public function testGetSignedUrlFallsBackWhenNativeSignerThrows(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns();
        $registry->register('throwing', $this->throwingNativeSigningFactory('throwing'));

        $config = [
            'default' => 's',
            'disks' => ['s' => ['driver' => 'throwing', 'base_url' => 'https://cdn.example']],
        ];
        $storage = new StorageManager($config, new \Glueful\Storage\PathGuard(), $registry);
        $urls = new UrlGenerator($config, new \Glueful\Storage\PathGuard());

        $fs = new FlysystemStorage($storage, $urls, 's');

        $this->assertSame('https://cdn.example/obj.txt', $fs->getSignedUrl('obj.txt', 120));
    }

    private function nonAtomicFactory(string $driver): StorageDriverFactoryInterface
    {
        return new class ($driver) implements StorageDriverFactoryInterface {
            public function __construct(private string $name)
            {
            }
            public function driver(): string
            {
                return $this->name;
            }
            public function create(array $config): FilesystemOperator
            {
                return new Filesystem(new InMemoryFilesystemAdapter());
            }
            public function available(array $config): bool
            {
                return true;
            }
            public function features(array $config): array
            {
                return ['supports_atomic_move' => false, 'cloud' => true];
            }
        };
    }

    private function nativeSigningFactory(string $driver): StorageDriverFactoryInterface
    {
        return new class ($driver) implements StorageDriverFactoryInterface, NativeSignedUrlProviderInterface {
            public function __construct(private string $name)
            {
            }
            public function driver(): string
            {
                return $this->name;
            }
            public function create(array $config): FilesystemOperator
            {
                return new Filesystem(new InMemoryFilesystemAdapter());
            }
            public function available(array $config): bool
            {
                return true;
            }
            public function features(array $config): array
            {
                return ['supports_native_signed_urls' => true];
            }
            public function temporaryUrl(string $path, int $ttl, array $diskConfig): ?string
            {
                return "https://signed.example/{$path}?ttl={$ttl}";
            }
        };
    }

    private function throwingNativeSigningFactory(string $driver): StorageDriverFactoryInterface
    {
        return new class ($driver) implements StorageDriverFactoryInterface, NativeSignedUrlProviderInterface {
            public function __construct(private string $name)
            {
            }
            public function driver(): string
            {
                return $this->name;
            }
            public function create(array $config): FilesystemOperator
            {
                return new Filesystem(new InMemoryFilesystemAdapter());
            }
            public function available(array $config): bool
            {
                return true;
            }
            public function features(array $config): array
            {
                return ['supports_native_signed_urls' => true];
            }
            public function temporaryUrl(string $path, int $ttl, array $diskConfig): ?string
            {
                throw new \RuntimeException('signing unavailable');
            }
        };
    }
```

- [ ] Run `vendor/bin/phpunit tests/Integration/Storage/FlysystemStorageTest.php` -- expect FAIL
      (the native-signer case fails: `getSignedUrl()` still returns `getUrl()`; the non-atomic store
      case should already pass once Task 3.1 centralizes atomic/direct writes in `StorageManager`).

- [ ] Modify `src/Uploader/Storage/FlysystemStorage.php`:
  - Add the import (only `NativeSignedUrlProviderInterface` is referenced by name; the registry is
    reached through `$this->storage->drivers()`, so do NOT import
    `StorageDriverRegistryInterface` -- it would be a dead import):

```php
use Glueful\Storage\Contracts\NativeSignedUrlProviderInterface;
```

  - Delete `isCloudDisk()`. Atomic-vs-direct write is now owned centrally by
    `StorageManager::putStream()`, so `store()` should not duplicate the feature check. Replace the
    body of `store()` and `getSignedUrl()`:

```php
    public function store(string $sourcePath, string $destinationPath): string
    {
        $stream = fopen($sourcePath, 'r');
        if ($stream === false) {
            throw new UploadException('Failed to open uploaded file');
        }

        try {
            $this->storage->putStream($destinationPath, $stream, $this->disk);
        } catch (\Throwable $e) {
            throw new UploadException('Storage write failed: ' . $e->getMessage(), 0, $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $destinationPath;
    }

    public function getSignedUrl(string $path, int $expiry = 3600): string
    {
        $cfg = $this->urls->diskConfig($this->disk);
        $driver = (string) ($cfg['driver'] ?? 'local');

        $registry = $this->storage->drivers();
        if ($registry->has($driver)) {
            $factory = $registry->get($driver);
            if ($factory instanceof NativeSignedUrlProviderInterface) {
                $ttl = $expiry > 0 ? $expiry : (int) ($cfg['signed_ttl'] ?? 3600);
                try {
                    return $factory->temporaryUrl($path, $ttl, $cfg) ?? $this->getUrl($path);
                } catch (\Throwable) {
                    return $this->getUrl($path);
                }
            }
        }

        return $this->getUrl($path);
    }
```

  - Remove the now-unused `use League\Flysystem\FilesystemException;` only if no longer referenced
    (it is still used in `storeContent()` -- keep it).

- [ ] Run `vendor/bin/phpunit tests/Integration/Storage/FlysystemStorageTest.php` -- expect PASS
      (`StorageManager::drivers()` already exists from Task 3.1).
- [ ] Run `composer run analyse:changed` -- expect PASS.
- [ ] Commit: "refactor(uploader): route writes and signed URLs through storage seams".

---

## Phase 6 -- storage:test diagnostics command

### Task 6.1 -- storage:test [disk] read-only by default, --write smoke test

**Files:**
- Create: `src/Console/Commands/Storage/StorageTestCommand.php`
- Create: `tests/Unit/Console/Commands/Storage/StorageTestCommandTest.php`

Steps:

- [ ] Write failing test `tests/Unit/Console/Commands/Storage/StorageTestCommandTest.php`. Test the
      pure reporting logic via a public static
      `buildReport(StorageDriverRegistryInterface $registry, array $storageConfig, bool $write): array`
      method so no app boot is needed (mirrors how other command-logic units are tested). For an
      unregistered driver, the report row's `message` is the pointed package-suggestion message
      (reusing `UnsupportedStorageDriverException::forDriver($driver)->getMessage()`, e.g.
      "... composer require glueful/storage-s3"):

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Commands\Storage;

use Glueful\Console\Commands\Storage\StorageTestCommand;
use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use Glueful\Storage\Contracts\StorageHealthCheckInterface;
use Glueful\Storage\StorageDriverRegistry;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class StorageTestCommandTest extends TestCase
{
    public function testReportsRegisteredAndAvailableForBuiltInLocal(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns();
        $config = [
            'default' => 'uploads',
            'disks' => ['uploads' => ['driver' => 'local', 'root' => sys_get_temp_dir()]],
        ];

        $report = StorageTestCommand::buildReport($registry, $config, false);

        $this->assertArrayHasKey('uploads', $report);
        $this->assertTrue($report['uploads']['registered']);
        $this->assertTrue($report['uploads']['available']);
        $this->assertFalse($report['uploads']['wrote']); // read-only by default
    }

    public function testReportsMissingDriverCleanlyWithoutThrowing(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns();
        $config = ['default' => 's3', 'disks' => ['s3' => ['driver' => 's3', 'bucket' => 'b']]];

        $report = StorageTestCommand::buildReport($registry, $config, false);

        $this->assertFalse($report['s3']['registered']);
        $this->assertFalse($report['s3']['available']);
        $this->assertStringContainsString('glueful/storage-s3', (string) $report['s3']['message']);
    }

    public function testRedactsSecretsFromReport(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns();
        $config = [
            'default' => 's3',
            'disks' => ['s3' => ['driver' => 's3', 'bucket' => 'b', 'secret' => 'TOP', 'key' => 'AKIA']],
        ];

        $report = StorageTestCommand::buildReport($registry, $config, false);
        $encoded = json_encode($report);

        $this->assertStringNotContainsString('TOP', (string) $encoded);
        $this->assertStringNotContainsString('AKIA', (string) $encoded);
    }

    public function testWriteFlagRunsSmokeTestOnMemoryDisk(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns();
        $config = ['default' => 'mem', 'disks' => ['mem' => ['driver' => 'memory']]];

        $report = StorageTestCommand::buildReport($registry, $config, true);

        $this->assertTrue($report['mem']['wrote']);
        $this->assertTrue($report['mem']['ok']);
    }

    public function testHealthCheckCapabilityIsInvokedReadOnly(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns();
        $registry->register('probed', $this->healthyFactory('probed'));
        $config = ['default' => 'p', 'disks' => ['p' => ['driver' => 'probed']]];

        $report = StorageTestCommand::buildReport($registry, $config, false);

        $this->assertTrue($report['p']['liveness']);
        $this->assertFalse($report['p']['wrote']); // still no write without --write
    }

    private function healthyFactory(string $driver): StorageDriverFactoryInterface
    {
        return new class ($driver) implements StorageDriverFactoryInterface, StorageHealthCheckInterface {
            public function __construct(private string $name)
            {
            }
            public function driver(): string
            {
                return $this->name;
            }
            public function create(array $config): FilesystemOperator
            {
                return new Filesystem(new InMemoryFilesystemAdapter());
            }
            public function available(array $config): bool
            {
                return true;
            }
            public function features(array $config): array
            {
                return [];
            }
            public function check(string $disk, array $diskConfig): array
            {
                return ['ok' => true, 'message' => 'reachable'];
            }
        };
    }
}
```

- [ ] Run `vendor/bin/phpunit --filter=StorageTestCommandTest` -- expect FAIL.

- [ ] Create `src/Console/Commands/Storage/StorageTestCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Storage;

use Glueful\Console\BaseCommand;
use Glueful\Storage\Contracts\StorageDriverRegistryInterface;
use Glueful\Storage\Contracts\StorageHealthCheckInterface;
use Glueful\Storage\Exceptions\UnsupportedStorageDriverException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'storage:test',
    description: 'Diagnose storage disks: registration, adapter availability, liveness (read-only by default)'
)]
final class StorageTestCommand extends BaseCommand
{
    /** @var array<int, string> Config keys whose values must never be printed. */
    private const SECRET_KEYS = ['secret', 'key', 'connection_string', 'password', 'token', 'sas'];

    protected function configure(): void
    {
        $this->setDescription('Diagnose storage disks (read-only by default)')
            ->addArgument('disk', InputArgument::OPTIONAL, 'Limit to a single disk name')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Run a write/read/delete smoke test');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var StorageDriverRegistryInterface $registry */
        $registry = container($this->getContext())->get(StorageDriverRegistryInterface::class);
        /** @var array<string, mixed> $config */
        $config = (array) config($this->getContext(), 'storage', []);

        $only = $input->getArgument('disk');
        if (is_string($only) && $only !== '') {
            $disks = $config['disks'] ?? [];
            $config['disks'] = isset($disks[$only]) ? [$only => $disks[$only]] : [];
        }

        $report = self::buildReport($registry, $config, (bool) $input->getOption('write'));

        $failed = false;
        foreach ($report as $disk => $row) {
            $status = ($row['available'] && ($row['liveness'] !== false) && ($row['ok'] !== false))
                ? '<info>OK</info>'
                : '<comment>CHECK</comment>';
            if (!$row['available'] || $row['ok'] === false) {
                $failed = true;
            }
            $output->writeln(sprintf(
                '%s  %-20s driver=%s registered=%s available=%s wrote=%s  %s',
                $status,
                $disk,
                (string) $row['driver'],
                $row['registered'] ? 'yes' : 'no',
                $row['available'] ? 'yes' : 'no',
                $row['wrote'] ? 'yes' : 'no',
                (string) $row['message']
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Pure, side-effect-light diagnostics builder (no console I/O), so it can be
     * unit-tested directly. Read-only unless $write is true. Never returns secrets.
     *
     * @param array<string, mixed> $storageConfig
     * @return array<string, array{
     *   driver: string, registered: bool, available: bool, liveness: bool|null,
     *   wrote: bool, ok: bool|null, message: string
     * }>
     */
    public static function buildReport(
        StorageDriverRegistryInterface $registry,
        array $storageConfig,
        bool $write
    ): array {
        $report = [];
        /** @var array<string, array<string, mixed>> $disks */
        $disks = (array) ($storageConfig['disks'] ?? []);

        foreach ($disks as $name => $diskConfig) {
            $driver = (string) ($diskConfig['driver'] ?? '');
            $registered = $registry->has($driver);

            $row = [
                'driver' => $driver,
                'registered' => $registered,
                'available' => false,
                'liveness' => null,
                'wrote' => false,
                'ok' => null,
                'message' => '',
            ];

            if (!$registered) {
                $row['message'] = self::missingMessage($driver);
                $report[(string) $name] = $row;
                continue;
            }

            $factory = $registry->get($driver);
            $row['available'] = $factory->available($diskConfig);
            if (!$row['available']) {
                $row['message'] = 'Driver registered but adapter/config unavailable.';
                $report[(string) $name] = $row;
                continue;
            }

            // Non-mutating liveness via the optional health-check capability.
            if ($factory instanceof StorageHealthCheckInterface) {
                $check = $factory->check((string) $name, $diskConfig);
                $row['liveness'] = (bool) ($check['ok'] ?? false);
                $row['message'] = (string) ($check['message'] ?? '');
            }

            if ($write) {
                $row = array_merge($row, self::smokeTest($factory->create($diskConfig)));
            } else {
                $row['ok'] = $row['liveness'];
                if ($row['message'] === '') {
                    $row['message'] = 'Registered and available (read-only check).';
                }
            }

            $report[(string) $name] = $row;
        }

        return $report;
    }

    /**
     * @return array{wrote: bool, ok: bool, message: string}
     */
    private static function smokeTest(\League\Flysystem\FilesystemOperator $fs): array
    {
        $probe = '.glueful-storage-test-' . bin2hex(random_bytes(6));
        try {
            $fs->write($probe, 'ok');
            $read = $fs->read($probe) === 'ok';
            $fs->delete($probe);

            return [
                'wrote' => true,
                'ok' => $read,
                'message' => $read ? 'Write/read/delete smoke test passed.' : 'Read-back mismatch.',
            ];
        } catch (\Throwable $e) {
            return ['wrote' => true, 'ok' => false, 'message' => 'Smoke test failed: ' . $e->getMessage()];
        }
    }

    private static function missingMessage(string $driver): string
    {
        return UnsupportedStorageDriverException::forDriver($driver)->getMessage();
    }
}
```

- [ ] Run `vendor/bin/phpunit --filter=StorageTestCommandTest` -- expect PASS.
- [ ] Run `php glueful commands:cache` then `php glueful storage:test uploads` -- expect the
      `uploads` disk reports OK and the command exits cleanly (manifest must be refreshed so the
      new command boots). The verification is deliberately scoped to the `uploads` disk: at this
      point `config/storage.php` still ships the `s3` stub disk, which (correctly) reports
      unregistered with the pointed "composer require glueful/storage-s3" message and a FAILURE
      exit. Task 8.2 comments that stub out (core's default config must only declare disks core
      can create); after Task 8.2, an unscoped `php glueful storage:test` exits cleanly on the
      default local/memory-only config -- that full-run check lives in Task 8.2.
- [ ] Commit: "feat(storage): add storage:test diagnostics command (read-only by default)".

---

## Phase 7 -- Native-URL exposure in the blob API

### Task 7.1 -- Optional, default-off, per-disk, visibility-scoped native_url

**Files:**
- Modify: `config/uploads.php`
- Modify: `src/Controllers/UploadController.php`
- Create: `tests/Unit/Controllers/UploadControllerNativeUrlTest.php`

Steps:

- [ ] Add the native-URL policy block to `config/uploads.php` (additive; default off):

```php
    // Native object-store URLs (opt-in, per-disk, visibility-scoped).
    //
    // When enabled for a disk whose storage factory implements
    // NativeSignedUrlProviderInterface, blob metadata/signed-url responses MAY
    // include an additive `native_url` field that points straight at the bucket
    // or CDN. This is an ADDITION, never a replacement: the app-signed
    // /blobs/{uuid} path stays the always-available, access-controlled URL.
    //
    // SECURITY: a native presigned URL is a time-boxed BEARER TOKEN that bypasses
    // app-side access control and revocation. `public` blobs may return a direct
    // URL (bandwidth offload). `private` blobs may only opt in with a bounded TTL.
    'native_urls' => [
        // Map of disk name => policy, keyed under 'disks'. Absent disk = disabled.
        'disks' => [
            // 'media' => ['enabled' => true, 'public' => true, 'private' => false, 'private_ttl' => 300],
        ],
        // Hard ceiling on the TTL handed to a native private signer (seconds).
        'max_private_ttl' => (int) env('UPLOADS_NATIVE_MAX_PRIVATE_TTL', 900),
    ],
```

- [ ] Write failing test `tests/Unit/Controllers/UploadControllerNativeUrlTest.php`. It covers two
      layers, neither needing app boot: (a) the pure policy helper (static `nativeUrlFor(...)`),
      and (b) the registry wiring helper (static `nativeUrlViaRegistry(...)`) -- the wiring case
      asserts the value handed to the native signer is the RAW stored object path, NOT the
      public/CDN-prefixed URL that `info()` writes back into `$blob['url']`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Controllers;

use Glueful\Controllers\UploadController;
use Glueful\Storage\Contracts\NativeSignedUrlProviderInterface;
use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use Glueful\Storage\PathGuard;
use Glueful\Storage\StorageDriverRegistry;
use Glueful\Storage\Support\UrlGenerator;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class UploadControllerNativeUrlTest extends TestCase
{
    public function testDisabledByDefaultReturnsNull(): void
    {
        $policy = ['disks' => [], 'max_private_ttl' => 900];
        $this->assertNull(UploadController::nativeUrlFor(
            policy: $policy,
            disk: 'media',
            visibility: 'public',
            signer: fn(int $ttl): ?string => "https://bucket/x?ttl={$ttl}",
            defaultTtl: 300
        ));
    }

    public function testPublicBlobReturnsNativeWhenEnabled(): void
    {
        $policy = ['disks' => ['media' => ['enabled' => true, 'public' => true]], 'max_private_ttl' => 900];
        $url = UploadController::nativeUrlFor(
            policy: $policy,
            disk: 'media',
            visibility: 'public',
            signer: fn(int $ttl): ?string => "https://bucket/x?ttl={$ttl}",
            defaultTtl: 300
        );
        $this->assertNotNull($url);
        $this->assertStringStartsWith('https://bucket/x', (string) $url);
    }

    public function testPrivateBlobRequiresExplicitOptInAndBoundsTtl(): void
    {
        // private not enabled -> null
        $disabled = ['disks' => ['media' => ['enabled' => true, 'public' => true, 'private' => false]], 'max_private_ttl' => 900];
        $this->assertNull(UploadController::nativeUrlFor(
            policy: $disabled,
            disk: 'media',
            visibility: 'private',
            signer: fn(int $ttl): ?string => "https://bucket/x?ttl={$ttl}",
            defaultTtl: 100000
        ));

        // private enabled -> TTL capped at max_private_ttl
        $enabled = [
            'disks' => ['media' => ['enabled' => true, 'private' => true, 'private_ttl' => 100000]],
            'max_private_ttl' => 900,
        ];
        $url = UploadController::nativeUrlFor(
            policy: $enabled,
            disk: 'media',
            visibility: 'private',
            signer: fn(int $ttl): ?string => "https://bucket/x?ttl={$ttl}",
            defaultTtl: 300
        );
        $this->assertSame('https://bucket/x?ttl=900', $url); // bounded
    }

    public function testSignerReturningNullFallsBackToNull(): void
    {
        $policy = ['disks' => ['media' => ['enabled' => true, 'public' => true]], 'max_private_ttl' => 900];
        $this->assertNull(UploadController::nativeUrlFor(
            policy: $policy,
            disk: 'media',
            visibility: 'public',
            signer: fn(int $ttl): ?string => null,
            defaultTtl: 300
        ));
    }

    public function testNativeSignerReceivesRawStoredPathNotPrefixedUrl(): void
    {
        // Wiring-level guard for the info() sequence: the blob row stores the
        // RAW object path in `url`; info() then overwrites $blob['url'] with the
        // public/CDN-prefixed URL via resolveBlobUrl(). The native signer must
        // be handed the raw path captured BEFORE that overwrite -- presigning
        // "https://cdn.../path" instead of the object key yields broken URLs.
        $recorded = new \ArrayObject([]);
        $registry = StorageDriverRegistry::withBuiltIns();
        $registry->register('recording', $this->recordingFactory($recorded));

        $diskConfig = [
            'driver' => 'recording',
            'base_url' => 'https://cdn.example.com', // public/CDN prefix in play
        ];
        $storageConfig = ['default' => 'media', 'disks' => ['media' => $diskConfig]];
        $urls = new UrlGenerator($storageConfig, new PathGuard());

        // Mirror UploadController::info() exactly:
        $blob = ['url' => 'docs/report.pdf', 'visibility' => 'public', 'storage_type' => 'media'];
        $rawPath = (string) ($blob['url'] ?? '');      // captured BEFORE the overwrite
        $blob['url'] = $urls->url($rawPath, 'media');  // now https://cdn.example.com/docs/report.pdf
        $this->assertSame('https://cdn.example.com/docs/report.pdf', $blob['url']); // overwrite happened

        $policy = ['disks' => ['media' => ['enabled' => true, 'public' => true]], 'max_private_ttl' => 900];

        $native = UploadController::nativeUrlViaRegistry(
            registry: $registry,
            policy: $policy,
            disk: 'media',
            diskConfig: $diskConfig,
            visibility: 'public',
            rawPath: $rawPath,
            defaultTtl: 300
        );

        $this->assertSame('https://signed.example/docs/report.pdf?ttl=300', $native);
        $this->assertCount(1, $recorded);
        $this->assertSame('docs/report.pdf', $recorded[0]); // RAW object path...
        $this->assertStringNotContainsString('https://cdn.example.com', (string) $recorded[0]); // ...never the prefixed URL
    }

    public function testNativeUrlViaRegistryReturnsNullForNonSigningFactory(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns(); // local/memory: no native signer

        $this->assertNull(UploadController::nativeUrlViaRegistry(
            registry: $registry,
            policy: ['disks' => ['media' => ['enabled' => true, 'public' => true]], 'max_private_ttl' => 900],
            disk: 'media',
            diskConfig: ['driver' => 'local', 'root' => sys_get_temp_dir()],
            visibility: 'public',
            rawPath: 'docs/report.pdf',
            defaultTtl: 300
        ));
    }

    /**
     * @param \ArrayObject<int, string> $recorded
     */
    private function recordingFactory(\ArrayObject $recorded): StorageDriverFactoryInterface
    {
        return new class ($recorded) implements StorageDriverFactoryInterface, NativeSignedUrlProviderInterface {
            /** @param \ArrayObject<int, string> $recorded */
            public function __construct(private \ArrayObject $recorded)
            {
            }
            public function driver(): string
            {
                return 'recording';
            }
            public function create(array $config): FilesystemOperator
            {
                return new Filesystem(new InMemoryFilesystemAdapter());
            }
            public function available(array $config): bool
            {
                return true;
            }
            public function features(array $config): array
            {
                return ['supports_native_signed_urls' => true];
            }
            public function temporaryUrl(string $path, int $ttl, array $diskConfig): ?string
            {
                $this->recorded->append($path);

                return "https://signed.example/{$path}?ttl={$ttl}";
            }
        };
    }
}
```

- [ ] Run `vendor/bin/phpunit --filter=UploadControllerNativeUrlTest` -- expect FAIL.

- [ ] Modify `src/Controllers/UploadController.php`:
  - Add the pure policy helper:

```php
    /**
     * Decide whether to expose a native object-store URL for a blob, and mint it.
     *
     * Opt-in, per-disk, visibility-scoped. Returns null (caller falls back to the
     * app-signed /blobs/{uuid} URL) unless the disk is explicitly enabled for the
     * blob's visibility. Private TTL is hard-capped at policy.max_private_ttl.
     *
     * @param array<string, mixed> $policy   config('uploads.native_urls')
     * @param callable(int): (string|null) $signer Mints the native URL for a TTL.
     */
    public static function nativeUrlFor(
        array $policy,
        string $disk,
        string $visibility,
        callable $signer,
        int $defaultTtl
    ): ?string {
        /** @var array<string, array<string, mixed>> $disks */
        $disks = (array) ($policy['disks'] ?? []);
        $diskPolicy = $disks[$disk] ?? null;
        if (!is_array($diskPolicy) || ($diskPolicy['enabled'] ?? false) !== true) {
            return null;
        }

        if ($visibility === 'public') {
            if (($diskPolicy['public'] ?? false) !== true) {
                return null;
            }
            return $signer($defaultTtl);
        }

        // private
        if (($diskPolicy['private'] ?? false) !== true) {
            return null;
        }

        $maxTtl = (int) ($policy['max_private_ttl'] ?? 900);
        $ttl = (int) ($diskPolicy['private_ttl'] ?? $defaultTtl);
        if ($ttl <= 0 || $ttl > $maxTtl) {
            $ttl = $maxTtl;
        }

        return $signer($ttl);
    }
```

  - Add the static registry wiring helper. It signs the RAW stored object path passed in
    explicitly -- it must NEVER read `$blob['url']`, because by the time `info()` calls it that
    field holds the public/CDN-prefixed URL from `resolveBlobUrl()` (which routes the raw stored
    path through `UrlGenerator::url()`, prefixing `cdn_base_url`/`base_url`). Static and pure so
    the raw-path contract is directly testable without app boot:

```php
    /**
     * Registry-aware native-URL wiring: resolves the disk's factory and signs
     * the RAW stored object path (never a public/CDN-prefixed URL -- presigning
     * the prefixed URL produces broken links on exactly the disks where
     * native_url matters). Returns null unless the factory implements
     * NativeSignedUrlProviderInterface and the policy allows exposure.
     *
     * @param array<string, mixed> $policy     config('uploads.native_urls')
     * @param array<string, mixed> $diskConfig storage.disks.{disk}
     */
    public static function nativeUrlViaRegistry(
        \Glueful\Storage\Contracts\StorageDriverRegistryInterface $registry,
        array $policy,
        string $disk,
        array $diskConfig,
        string $visibility,
        string $rawPath,
        int $defaultTtl
    ): ?string {
        if ($rawPath === '') {
            return null;
        }

        $driver = (string) ($diskConfig['driver'] ?? '');
        if (!$registry->has($driver)) {
            return null;
        }

        $factory = $registry->get($driver);
        if (!$factory instanceof \Glueful\Storage\Contracts\NativeSignedUrlProviderInterface) {
            return null;
        }

        return self::nativeUrlFor(
            $policy,
            $disk,
            $visibility,
            fn(int $ttl): ?string => $factory->temporaryUrl($rawPath, $ttl, $diskConfig),
            $defaultTtl
        );
    }
```

  - Add the instance bridge that reads config and delegates. Its signature REQUIRES the raw path
    as an explicit parameter so callers cannot accidentally hand it the rewritten `$blob['url']`:

```php
    /**
     * @param array<string, mixed> $blob
     * @param string $rawPath The blob row's stored object path, captured BEFORE
     *                        any $blob['url'] rewrite to a public URL.
     */
    private function maybeNativeUrl(array $blob, string $rawPath): ?string
    {
        /** @var array<string, mixed> $policy */
        $policy = (array) $this->getConfig('uploads.native_urls', []);
        if (($policy['disks'] ?? []) === []) {
            return null; // default off -> no work, no native field
        }

        $disk = $this->resolveDisk($blob);

        return self::nativeUrlViaRegistry(
            $this->storage->drivers(),
            $policy,
            $disk,
            (array) $this->urls->diskConfig($disk),
            (string) ($blob['visibility'] ?? 'private'),
            $rawPath,
            (int) $this->getConfig('uploads.signed_urls.ttl', 3600)
        );
    }
```

  - Wire it additively into `info()`. The RAW stored path MUST be captured BEFORE the existing
    `$blob['url'] = $this->resolveBlobUrl($blob);` overwrite (currently line 140) -- after it,
    `$blob['url']` is the public/CDN-prefixed URL. Replace the `info()` body section:

```php
        $rawPath = (string) ($blob['url'] ?? ''); // RAW stored object path -- BEFORE the overwrite
        $blob['url'] = $this->resolveBlobUrl($blob);

        $native = $this->maybeNativeUrl($blob, $rawPath);
        if ($native !== null) {
            $blob['native_url'] = $native;
        }

        return Response::success($blob, 'Blob metadata');
```

  - Add the same additive `native_url` injection to the `signedUrl()` success payload, keeping
    `signed_url` (the app-signed `/blobs/{uuid}`) as the primary, always-present field. Note:
    unlike `info()`, `signedUrl()` never rewrites `$blob['url']`, so at this point it still holds
    the RAW stored object path -- the same raw-path rule applies, the capture is just trivially
    safe here. Replace the return block at the end of `signedUrl()`:

```php
        $baseUrl = $request->getSchemeAndHttpHost() . '/blobs/' . $uuid;
        $signedUrl = SignedUrl::make($this->getContext())->generate($baseUrl, $ttl);

        // signedUrl() does not rewrite $blob['url']; it still holds the raw stored path.
        $rawPath = (string) ($blob['url'] ?? '');
        $native = $this->maybeNativeUrl($blob, $rawPath);

        $payload = [
            'uuid' => $uuid,
            'signed_url' => $signedUrl,
            'expires_in' => $ttl,
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
        ];
        if ($native !== null) {
            $payload['native_url'] = $native;
        }

        return Response::success($payload, 'Signed URL generated');
```

- [ ] Run `vendor/bin/phpunit --filter=UploadControllerNativeUrlTest` -- expect PASS.
- [ ] Run `vendor/bin/phpunit tests/Unit/Uploader/FileUploaderNoMediaTest.php` -- expect PASS
      (no native config -> no behavior change; app-signed path untouched).
- [ ] Run `composer run analyse:changed` -- expect PASS.
- [ ] Commit: "feat(uploads): optional, visibility-scoped native_url exposure in blob API".

---

## Phase 8 -- Regression test, config docs, and release notes

### Task 8.1 -- Effective-disk regression gate (existing test; fix already on dev)

**Files:**
- None -- uses the existing `tests/Unit/Uploader/FileUploaderNoMediaTest.php` unchanged.

> The `FileUploader::effectiveDisk()` fix already landed on `dev` (CHANGELOG [Unreleased] "Blob
> uploads now persist the actual effective storage disk"), and the existing test
> `FileUploaderNoMediaTest::testExplicitStorageDriverIsPersistedAsBlobStorageType` (line ~169)
> already proves it: it constructs `new FileUploader(storageDriver: 'assets', context:
> $this->context)`, uploads through the booted harness, and asserts the persisted
> `blobs.storage_type === 'assets'` (not the configured `uploads.disk`). This task ONLY re-runs
> that existing test as the regression gate so the registry refactor cannot silently regress it.
> Do not modify `FileUploader`; do not add a new test file.

Steps:

- [ ] Run `vendor/bin/phpunit --filter=testExplicitStorageDriverIsPersistedAsBlobStorageType` --
      expect PASS (fix already in place). If it FAILS, stop: a prior task regressed the
      effective-disk behavior -- fix the regression, do not weaken or duplicate the test.
- [ ] No commit (no files change in this task).

### Task 8.2 -- config/storage.php cleanup: pack comments + comment out the s3 stub disk

**Files:**
- Modify: `config/storage.php`

Steps:

- [ ] Update the header doc-comment to reflect that `s3`/`gcs`/`azure` are now provided by
      first-party packs -- change the "Optional adapters (install via Composer)" section to name
      the Glueful packs instead of raw flysystem adapters:

```php
 * Included drivers (core):
 *   - local:  Local filesystem
 *   - memory: In-memory storage for testing
 *
 * Provider drivers ship as first-party packs (install the one you use):
 *   - S3 / R2 / MinIO / Spaces / Wasabi:  composer require glueful/storage-s3
 *   - Google Cloud Storage:               composer require glueful/storage-gcs
 *   - Azure Blob Storage:                 composer require glueful/storage-azure
 *
 * Without the matching pack, a disk using that driver fails fast with a pointed
 * "composer require glueful/storage-*" error (UnsupportedStorageDriverException).
```

- [ ] **Comment out the `s3` stub disk** (policy: core's default config must only declare disks
      core can create -- a live `s3` stub would make `php glueful storage:test` report an
      unregistered driver and exit FAILURE on a plain framework checkout). Keep the block as a
      commented template so an app installing the pack can uncomment it:

```php
        // Optional S3-compatible disk.
        // Requires glueful/storage-s3: composer require glueful/storage-s3
        // (core no longer ships the s3 driver; uncomment after installing the pack)
        // 's3' => [
        //     'driver' => 's3',
        //     'key' => env('S3_ACCESS_KEY_ID'),
        //     'secret' => env('S3_SECRET_ACCESS_KEY'),
        //     'region' => env('S3_REGION', 'us-east-1'),
        //     'bucket' => env('S3_BUCKET'),
        //     'endpoint' => env('S3_ENDPOINT'),
        //     'use_path_style_endpoint' => true,
        //
        //     // Optional behavior hints
        //     'acl' => env('S3_ACL', 'private'),
        //     'signed_urls' => env('S3_SIGNED_URLS', true),
        //     'signed_ttl' => (int) env('S3_SIGNED_URL_TTL', 3600),
        //     'cdn_base_url' => env('S3_CDN_BASE_URL'),
        // ],
```

- [ ] Run `vendor/bin/phpunit --filter=StorageManagerRegistryTest` -- expect PASS (config still
      parses).
- [ ] Run `php glueful storage:test` -- expect every configured disk reports OK and the command
      exits SUCCESS. This completes the verification deferred from Task 6.1: with the `s3` stub
      commented out, the framework's default (local/memory-only) config passes an unscoped
      `storage:test` run.
- [ ] Commit: "chore(config): comment out s3 stub disk; point storage comments at provider packs".

### Task 8.3 -- CHANGELOG entries (coordinated breaking release with Plan B)

**Files:**
- Modify: `CHANGELOG.md`

Steps:

- [ ] Under `## [Unreleased]`, add a `### Breaking Changes` section (the repo's convention for
      breaking entries -- see the 1.52.0 release, which placed `### Breaking Changes` first) and an
      `### Added` section after it. State the breaking nature and the Plan B coordination:

```markdown
### Breaking Changes
- **Core ships only the `local`/`memory` storage drivers.** The `s3`, `gcs`, and `azure` driver factories (and the embedded S3 presign logic) have been removed from core and extracted to first-party packs -- this is a coordinated breaking release with `glueful/storage-s3` (also covers R2 / MinIO / Spaces / Wasabi via presets), `glueful/storage-gcs`, and `glueful/storage-azure`. A disk using one of those drivers now fails fast with `UnsupportedStorageDriverException` naming the package to install (e.g. `composer require glueful/storage-s3`). The `s3` stub disk in `config/storage.php` is now commented out (core's default config only declares disks core can create). **Upgrade:** after updating the framework, `composer require glueful/storage-{s3,gcs,azure}` for whichever driver your app uses. Apps on `local`/`memory` only are unaffected.

### Added
- **Storage driver registry + provider seam.** New `Glueful\Storage\Contracts\StorageDriverFactoryInterface` (driver identity, construction, `available()`, `features()`), `StorageDriverRegistryInterface` (+ `StorageDriverRegistry::withBuiltIns()`), and optional capability contracts `NativeSignedUrlProviderInterface` / `StorageHealthCheckInterface`. `StorageManager` now resolves every disk through the registry (last-registered-wins per driver), accepts a nullable registry (defaults to built-ins -- `new StorageManager($config, $pathGuard)` is unchanged), drives `putStream()` through `features()['supports_atomic_move']` (default true), and exposes the registry via `drivers()`. Extensions register factories tagged `storage.driver_factory`; `StorageProvider` collects them after the `local`/`memory` built-ins and reverses the tagged iterator so higher tag priority wins same-driver collisions. `FlysystemStorage` delegates writes to `StorageManager::putStream()` and resolves native signed URLs via `NativeSignedUrlProviderInterface` (falling back to the app URL on `null` or provider errors).
- **`storage:test [disk]` command.** Read-only by default (reports driver registration, adapter `available()`, and a non-mutating liveness probe via the health-check capability); `--write` opts into a write/read/delete smoke test. Never prints secrets.
- **Optional `native_url` in the blob API.** Additive, default-off, per-disk, visibility-scoped (`config('uploads.native_urls')`): `public` blobs may serve a direct provider URL; `private` blobs only with a bounded TTL. The app-signed `/blobs/{uuid}` URL stays the always-available, access-controlled path.
```

- [ ] Run `composer run phpcs` and `composer run analyse:changed` -- expect PASS across all changed
      files (final gate before release coordination).
- [ ] Commit: "docs(changelog): storage driver registry seam + breaking core-driver shrink".

---

## Sequencing and Release Note

- This is **Plan A** of a coordinated, **breaking** release. Plan A (this plan) ships the registry
  seam and shrinks core's default driver set to `local`/`memory`. **Plan B** ships the first-party
  packs (`glueful/storage-s3` incl. R2/MinIO/Spaces/Wasabi presets, `glueful/storage-gcs`,
  `glueful/storage-azure`), each registering a `*StorageDriverFactory` tagged `storage.driver_factory`
  with `NativeSignedUrlProviderInterface` + `StorageHealthCheckInterface` where the provider supports
  them.
- They ship **together**: the framework releases the seam + the breaking shrink + upgrade notes + the
  pointed missing-driver error; the packs publish against that framework version. Apps upgrade, then
  `composer require glueful/storage-{s3,gcs,azure}` for the driver they use. Same dance as the
  `glueful/users` and `glueful/media` extractions.
- Do not release Plan A standalone without Plan B published, or apps using `s3`/`gcs`/`azure` break
  with no pack to install.

## Spec Coverage Map

- Spec Design 1 (registry, built-ins only, missing-driver UX, tagged registration, duplicate policy)
  -> Tasks 1.1, 1.2, 1.4, 2.1, 2.2, 2.3, 3.1, 4.1.
- Spec Design 2 (feature metadata, atomic-move default true) -> Tasks 1.1, 2.1, 2.2, 5.1.
- Spec Design 3 (native signed URL seam + public blob exposure, opt-in/visibility-scoped) -> Tasks
  1.3, 5.1, 7.1.
- Spec Design 4 (health-check contract + `storage:test`, read-only default, `--write`, no secrets)
  -> Tasks 1.3, 6.1.
- Spec Design 5 (effective-disk precedence preserved) -> Task 8.1 (regression guard only; fix already
  on dev).
- Spec Design 6 (`storage_type` rename gated on Lemma) -> no code change this plan; noted as a
  deferred decision (see Blockers). `storage_type` retains the disk-name value.
- Spec Design 7 / Decisions 2-3 (packs ship together; coordinated breaking release) -> Sequencing
  section + Task 8.3; pack implementation is Plan B.
- Spec Decisions 1, 4, 6, 7, 8, 9, 10, 11 -> Tasks 1.1-1.4, 3.1, 4.1, 5.1, 6.1, 7.1.
- Spec Testing Requirements -> Tasks 2.x (built-ins), 3.1 (create/diskExists/unknown/fake/override),
  4.1 (tagged collection + override), 3.1/5.1 (atomic move + default + native signer), 6.1 (secrets +
  missing adapters), 7.1 (native_url policy), 8.1 (effective disk).

## Blockers / Notes

- **No blockers.** All referenced symbols exist and signatures were verified against source.
- **API note (FlysystemStorage needs registry access):** the spec sketch passes a `$registry` into
  `getSignedUrl()` directly, but `FlysystemStorage` is constructed with `(StorageManager, UrlGenerator,
  string $disk)` and that signature is depended on by `FileUploader` and `FlysystemStorageTest`. To
  avoid a constructor-signature break, this plan adds a `StorageManager::drivers()` accessor (Task 3.1)
  and reaches the registry through the already-injected `StorageManager`. Behavior matches the spec;
  only the access path differs. Flagged here so the implementer does not "correct" it back to a new
  constructor param.
- **Known residual coupling (follow-up, out of scope):** `FileUploader::createFallbackStorage()`
  keeps an `'s3'` driver-name branch (CDN base-URL hint) and constructs
  `new StorageManager($cfg, new PathGuard())` -- i.e. built-ins only, no injected registry. After
  the extraction, pack-provided drivers can never resolve through that fallback path (any
  non-built-in driver hits `UnsupportedStorageDriverException` there). Flagged as follow-up
  cleanup; do not address it in this plan.
- **Deferred (Spec Design 6 / Decision 5):** the `blobs.storage_type` -> `storage_disk` rename is
  intentionally NOT done here; it is gated on Lemma's timeline. This plan keeps `storage_type` storing
  the configured disk name. If Lemma is scheduled to read the column before this ships, raise the
  rename as a separate coordinated migration -- out of scope for Plan A.
- **Manifest refresh:** adding `StorageTestCommand` requires `php glueful commands:cache` (not
  `cache:clear`) so the CLI boots the new command; Task 6.1 includes that step.
