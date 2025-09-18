# Flysystem Migration Implementation Plan

## Executive Summary

This document outlines the migration strategy for replacing Symfony's Filesystem component with League Flysystem v3 in the Glueful Framework. This migration provides a unified storage abstraction layer, enabling seamless switching between local and cloud storage providers with a clean, modern API.

**Important:** This migration only replaces `symfony/filesystem`. The `symfony/finder` component remains unchanged as it provides essential discovery capabilities that Flysystem cannot replace.

## Current State Analysis

### Components Being Removed and Replaced

**FileManager Service** (`src/Services/FileManager.php`)
- Core service wrapping Symfony\Component\Filesystem
- Used by 14+ components throughout the framework
- Provides atomic file operations, security validation, and logging
- **Will be completely removed and replaced with StorageManager using Flysystem**
- **No deprecation period - direct replacement since Glueful is not live**

### Components Staying Unchanged

**FileFinder Service** (`src/Services/FileFinder.php`)
- Wraps Symfony\Component\Finder for advanced file discovery
- Provides specialized search with date filtering, depth control, custom filters, content searching
- **Remains unchanged - Symfony Finder is essential for complex discovery operations**

### Dependency Clarification

| Component | Current | After Migration | Reason |
|-----------|---------|-----------------|---------|
| File I/O Operations | symfony/filesystem | league/flysystem | Storage abstraction, cloud support |
| File Discovery | symfony/finder | symfony/finder (no change) | Complex filtering capabilities required |
| FileManager Service | Uses Filesystem | Uses StorageManager | Migrated to Flysystem |
| FileFinder Service | Uses Finder | Uses Finder (no change) | Discovery features irreplaceable |

## Migration Strategy

### Core Principles

1. **Clean Separation**: Storage operations (Flysystem) vs Discovery operations (Symfony Finder)
2. **Single Storage Service**: One `StorageManager` service for all I/O operations
3. **Security First**: PathGuard for all storage operations
4. **No Discovery Abstraction**: Use Symfony Finder for local discovery, Flysystem's `listContents()` for basic cloud listing

### Discovery Policy

**Important: Glueful maintains strict boundaries for file discovery:**

- **Symfony Finder is NOT exposed** - It remains internal to FileFinder service only
- **Local disk discovery** - Available exclusively via FileFinder service using Symfony Finder
- **Cloud disk discovery** - Only supports `listContents()` with manual filtering
- **No cross-disk discovery** - Advanced discovery on cloud files requires mirroring to local first

```php
// ❌ NEVER do this - Symfony Finder is not exposed
use Symfony\Component\Finder\Finder;  // Not available in application code

// ✅ Correct approach for local discovery
$finder = app(FileFinder::class);
$files = $finder->findMigrations($path);

// ✅ Correct approach for cloud discovery
$files = $storage->disk('s3')->listContents('uploads');
foreach ($files as $file) {
    // Manual filtering logic
}
```

This policy prevents contributors from accidentally attempting to use Finder in cloud contexts and maintains clear architectural boundaries.

### What Changes vs What Stays

```php
// ✅ CHANGES: Storage Operations (symfony/filesystem → Flysystem)
$fileManager->writeFile($path, $content);        // OLD
$storage->disk()->write($path, $content);        // NEW

$fileManager->remove($path);                     // OLD
$storage->disk()->delete($path);                 // NEW

// ✅ STAYS: Discovery Operations (symfony/finder unchanged)
$fileFinder->findMigrations($path);              // NO CHANGE
$fileFinder->findCacheFiles($path, '*.gz', '< 7 days');  // NO CHANGE
$fileFinder->createFinder()->depth('== 0')->filter($callback);  // NO CHANGE
```

## Phase 1: Foundation (Week 1)

### 1.1 Install Dependencies

#### Core Dependencies (Required)
```json
{
    "require": {
        "symfony/finder": "^7.0",           // KEEP - for discovery
        "league/flysystem": "^3.0",         // ADD - for storage abstraction
        "league/flysystem-local": "^3.0"    // ADD - local adapter
    },
    "require-dev": {
        "league/flysystem-memory": "^3.0"   // ADD - for testing
    }
}
// REMOVE: "symfony/filesystem"
// REMOVE: "aws/aws-sdk-php" - replaced by optional Flysystem adapters
```

#### Optional Cloud Storage Adapters
These are **NOT part of Glueful core**. Install only if needed:

```json
{
    "require": {
        // S3-compatible storage (AWS S3, MinIO, DigitalOcean Spaces)
        "league/flysystem-aws-s3-v3": "^3.0",

        // Other cloud providers
        "league/flysystem-azure-blob-storage": "^3.0",
        "league/flysystem-google-cloud-storage": "^3.0",

        // Additional adapters
        "league/flysystem-ftp": "^3.0",
        "league/flysystem-sftp-v3": "^3.0"
    }
}
```

**Installation example for S3 support:**
```bash
composer require league/flysystem-aws-s3-v3:^3.0
```

### 1.2 Create Core Storage Layer

**Directory Structure:**
```
src/Storage/
├── StorageManager.php          # Main storage orchestrator
├── PathGuard.php               # Security layer
├── Exceptions/
│   ├── StorageException.php   # Base exception
│   ├── FileNotFoundException.php
│   ├── PermissionDeniedException.php
│   └── InvalidPathException.php
└── Support/
    └── UrlGenerator.php        # Safe URL generation
```

### 1.3 StorageManager Implementation

```php
namespace Glueful\Storage;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\Visibility;

class StorageManager
{
    private array $disks = [];
    private PathGuard $pathGuard;
    private array $config;

    public function __construct(array $config, PathGuard $pathGuard)
    {
        $this->config = $config;
        $this->pathGuard = $pathGuard;
    }

    /**
     * Get a filesystem disk instance
     */
    public function disk(?string $name = null): FilesystemOperator
    {
        $name = $name ?: $this->config['default'];

        if (!isset($this->disks[$name])) {
            $this->disks[$name] = $this->createDisk($name);
        }

        return $this->disks[$name];
    }

    /**
     * Check if a disk is configured and available
     */
    public function diskExists(string $name): bool
    {
        // Check if disk is configured
        if (!isset($this->config['disks'][$name])) {
            return false;
        }

        // For cloud disks, check if adapter is installed
        $driver = $this->config['disks'][$name]['driver'];

        return match($driver) {
            's3' => class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class),
            'azure' => class_exists(\League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter::class),
            'gcs' => class_exists(\League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter::class),
            'ftp' => class_exists(\League\Flysystem\Ftp\FtpAdapter::class),
            'sftp' => class_exists(\League\Flysystem\PhpseclibV3\SftpAdapter::class),
            'local', 'memory' => true,
            default => false,
        };
    }

    /**
     * JSON helpers for common operations
     */
    public function putJson(string $path, mixed $data, ?string $disk = null): void
    {
        $path = $this->pathGuard->validate($path);
        $content = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $this->disk($disk)->write($path, $content);
    }

    public function getJson(string $path, ?string $disk = null): mixed
    {
        $path = $this->pathGuard->validate($path);
        $content = $this->disk($disk)->read($path);
        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Atomic file write for large files
     */
    public function putStream(string $path, $stream, ?string $disk = null): void
    {
        $path = $this->pathGuard->validate($path);
        $temp = $this->generateTempPath($path);

        try {
            $this->disk($disk)->writeStream($temp, $stream);
            $this->disk($disk)->move($temp, $path);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            // Clean up temp file if it still exists
            try {
                $this->disk($disk)->delete($temp);
            } catch (\Exception $e) {
                // Ignore - temp file may have been moved
            }
        }
    }

    /**
     * Basic listing for cloud disks
     * For advanced discovery, use FileFinder on local disks
     */
    public function listContents(string $path, bool $recursive = false, ?string $disk = null): iterable
    {
        $path = $this->pathGuard->validate($path);
        return $this->disk($disk)->listContents($path, $recursive);
    }

    /**
     * Generate temporary URL for file access
     *
     * ⚠️ IMPORTANT: This is adapter-specific!
     * Supported by: S3 (AWS), Azure Blob Storage, Google Cloud Storage
     * NOT supported by: Local, FTP, SFTP adapters
     *
     * @throws \BadMethodCallException if adapter doesn't support temporary URLs
     */
    public function temporaryUrl(string $path, \DateTimeInterface $expiresAt, ?string $disk = null): string
    {
        $path = $this->pathGuard->validate($path);

        try {
            return $this->disk($disk)->temporaryUrl($path, $expiresAt);
        } catch (\BadMethodCallException $e) {
            $diskName = $disk ?: $this->config['default'];
            throw new \RuntimeException(
                "Disk '{$diskName}' does not support temporary URLs. " .
                "Only cloud storage adapters (S3, Azure, GCS) support this feature."
            );
        }
    }
}
```

### 1.4 PathGuard Implementation

```php
namespace Glueful\Storage;

use Glueful\Storage\Exceptions\InvalidPathException;

class PathGuard
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'allow_absolute' => false,
            'max_path_length' => 4096,
            'forbidden_patterns' => ['..', "\0"],
        ], $config);
    }

    /**
     * Validate and normalize path
     * @throws InvalidPathException
     */
    public function validate(string $path): string
    {
        // Check for null bytes (security)
        if (strpos($path, "\0") !== false) {
            throw new InvalidPathException("Path contains null byte");
        }

        // Reject path traversal
        if (str_contains($path, '..')) {
            throw new InvalidPathException("Path traversal detected");
        }

        // Reject absolute paths
        if (!$this->config['allow_absolute'] && $this->isAbsolute($path)) {
            throw new InvalidPathException("Absolute paths not allowed");
        }

        // Normalize path
        $path = $this->normalize($path);

        // Check length
        if (strlen($path) > $this->config['max_path_length']) {
            throw new InvalidPathException("Path exceeds maximum length");
        }

        return $path;
    }

    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path);
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        $path = preg_replace('#(\./)+#', '', $path);
        return $path;
    }

    private function isAbsolute(string $path): bool
    {
        return $path[0] === '/' || preg_match('/^[a-zA-Z]:/', $path);
    }
}
```

## Phase 2: Service Registration (Week 1)

### 2.1 Update Service Provider

Glueful now ships a Container provider for storage (and a transitional DI provider during migration):

- New container: `Glueful\Container\Providers\StorageProvider` registers `PathGuard`, `StorageManager`, and `UrlGenerator` from `config/storage.php`.
- Legacy DI (optional): `Glueful\DI\ServiceProviders\StorageServiceProvider` for older bootstrap paths.

Resolve services in application code:
```php
use Glueful\Storage\StorageManager;
use Glueful\Storage\Support\UrlGenerator;

$storage = app(StorageManager::class);
$urls = app(UrlGenerator::class);
```

```php
namespace Glueful\DI\ServiceProviders;

class StorageServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $container): void
    {
        // Register PathGuard
        $container->register(PathGuard::class)
            ->setArguments(['%storage.path_guard%'])
            ->setPublic(false);

        // Register StorageManager
        $container->register(StorageManager::class)
            ->setArguments([
                '%storage%',
                new Reference(PathGuard::class)
            ])
            ->setPublic(true);

        // Alias for convenience
        $container->setAlias('storage', StorageManager::class);

        // FileManager service is completely removed - replaced by StorageManager
        // No deprecation needed since Glueful is not live

        // FileFinder remains unchanged - still uses Symfony Finder
        $container->register(FileFinder::class)
            ->setFactory([$this, 'createFileFinder'])
            ->setArguments([new Reference('logger'), '%filesystem.file_finder%'])
            ->setPublic(true);
    }
}
```

## Phase 3: Migration Implementation (Week 2)

### 3.1 Migration Cheatsheet - Storage Operations Only

| Operation | Old (FileManager/Filesystem) | New (StorageManager/Flysystem) |
|-----------|------------------------------|--------------------------------|
| **Check file exists** | | |
| | ```php`$this->fileManager->exists($path)` | ```php`$storage->disk()->fileExists($path)` |
| **Read file** | | |
| | ```php`$fileManager->readFile('config.php')` | ```php`$storage->disk()->read('config.php')` |
| **Write file** | | |
| | ```php`$fileManager->writeFile(`<br>`    'cache.json',`<br>`    json_encode($data),`<br>`    0644`<br>`);` | ```php`$storage->putJson('cache.json', $data);`<br>`// or`<br>`$storage->disk()->write('cache.json', json_encode($data));`<br>`$storage->disk()->setVisibility('cache.json', Visibility::PUBLIC);` |
| **Create directory** | | |
| | ```php`$fileManager->createDirectory(`<br>`    'uploads/' . $userId,`<br>`    0755`<br>`);` | ```php`$storage->disk()->createDirectory(`<br>`    'uploads/' . $userId`<br>`);` |
| **Delete file/directory** | | |
| | ```php`$fileManager->remove($path)` | ```php`// Automatic detection`<br>`if ($storage->disk()->directoryExists($path)) {`<br>`    $storage->disk()->deleteDirectory($path);`<br>`} else {`<br>`    $storage->disk()->delete($path);`<br>`}` |
| **Move file** | | |
| | ```php`$fileManager->move($from, $to, true)` | ```php`$storage->disk()->move($from, $to)` |
| **Stream large file** | | |
| | ```php`$content = file_get_contents('large.zip');`<br>`$fileManager->writeFile('backup.zip', $content);` | ```php`$stream = fopen('large.zip', 'r');`<br>`$storage->putStream('backup.zip', $stream);` |

### 3.2 What Stays the Same - Discovery Operations

```php
// ✅ ALL FileFinder operations remain UNCHANGED

// Finding migrations with pattern and filter
$migrations = $fileFinder->findMigrations($migrationsPath);

// Finding old cache files with date filter
$oldFiles = $fileFinder->findCacheFiles($archiveDir, '*.gz', '14 days ago');

// Finding extensions with depth control
$extensions = $fileFinder->findExtensions($extensionsPath);

// Creating Symfony Finder directly
$finder = $fileFinder->createFinder()
    ->files()
    ->in($path)
    ->name('*.php')
    ->depth('== 0')
    ->date('< 7 days ago')
    ->filter(function($file) { /* custom logic */ })
    ->contains('namespace');

// All of these continue to work exactly as before
```

### 3.3 Cloud Storage Support (Optional)

#### Installing S3 Support

S3 support is **NOT included in Glueful core**. To use S3 storage:

```bash
# Install the S3 adapter
composer require league/flysystem-aws-s3-v3:^3.0

# Configure in config/storage.php
'disks' => [
    's3' => [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
        'bucket' => env('AWS_BUCKET'),
        'visibility' => Visibility::PRIVATE,
    ],
]
```

#### Using S3 Storage

```php
// Check if S3 adapter is installed
if (!class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class)) {
    throw new \RuntimeException('S3 support not installed. Run: composer require league/flysystem-aws-s3-v3');
}

// Basic S3 operations
$storage->disk('s3')->write('uploads/file.pdf', $content);
$url = $storage->disk('s3')->temporaryUrl('uploads/file.pdf', now()->addHour());
```

#### Cloud Discovery Limitations - Manual Filtering Required

**⚠️ IMPORTANT: Cloud storage does NOT have Symfony Finder's advanced filtering.**
**You MUST implement filtering manually when working with cloud disks.**

❌ **NOT AVAILABLE on cloud disks:**
```php
$finder->date('< 7 days ago')
$finder->size('> 10M')
$finder->depth('== 0')
$finder->contains('search text')
```

✅ **Instead, use manual filtering:**

### Date Filtering
Replaces Finder's `date()` method with manual timestamp checking:

```php
$cutoffTime = strtotime('-7 days');
$oldFiles = [];

foreach ($storage->disk('s3')->listContents('backups', true) as $file) {
    if ($file->isFile()) {
        $lastModified = $file->lastModified();

        if ($lastModified && $lastModified < $cutoffTime) {
            $oldFiles[] = $file;
        }
    }
}
```

### Size Filtering
Replaces Finder's `size()` method with manual size checking:

```php
$largeFiles = [];
$maxSize = 10 * 1024 * 1024; // 10MB

foreach ($storage->disk('s3')->listContents('uploads', true) as $file) {
    if ($file->isFile()) {
        $fileSize = $file->fileSize();

        if ($fileSize && $fileSize > $maxSize) {
            $largeFiles[] = $file;
        }
    }
}
```

### Pattern Filtering
Basic pattern matching using `fnmatch()`:

```php
$images = [];

foreach ($storage->disk('s3')->listContents('media', true) as $file) {
    if ($file->isFile() && fnmatch('*.{jpg,png,gif}', basename($file->path()), FNM_CASEFOLD)) {
        $images[] = $file;
    }
}
```

### Depth Filtering
Replaces Finder's `depth()` method with manual path segment counting:

```php
$topLevelOnly = [];

foreach ($storage->disk('s3')->listContents('docs', true) as $file) {
    $depth = substr_count($file->path(), '/');

    if ($depth === 1) {  // Top level only (like depth('== 0'))
        $topLevelOnly[] = $file;
    }
}
```

#### Cloud Storage Capabilities

✅ **All adapters support core storage operations:**
- Read/write files
- Move/copy/delete
- Stream handling
- Visibility settings
- Basic listing with `listContents()`

⚠️ **Adapter-specific features:**

| Feature | Local | FTP/SFTP | S3 | Azure | GCS |
|---------|-------|----------|-----|-------|-----|
| Basic Operations | ✅ | ✅ | ✅ | ✅ | ✅ |
| Stream Support | ✅ | ✅ | ✅ | ✅ | ✅ |
| Temporary URLs | ❌ | ❌ | ✅ | ✅ | ✅ |
| Public URLs | ✅* | ❌ | ✅ | ✅ | ✅ |
| MIME Type Detection | ✅ | Limited | ✅ | ✅ | ✅ |
| Directory Listing | ✅ | ✅ | ✅ | ✅ | ✅ |

*Local adapter only for public disk with web server configuration

⚠️ **For advanced discovery operations:**

| Finder Method | Cloud Replacement | See Example |
|---------------|-------------------|-------------|
| `->date('< 7 days')` | Manual `lastModified()` check | Section above |
| `->size('> 10M')` | Manual `fileSize()` check | Section above |
| `->depth('== 0')` | Count path segments | Section above |
| `->name('*.jpg')` | `fnmatch()` on basename | Section above |
| `->contains('text')` | **NOT POSSIBLE** - Download first | N/A |
| `->filter(callback)` | Manual loop with custom logic | Custom code |

**Solutions for complex discovery:**
1. Use manual filtering as shown above
2. Mirror to local first, then use FileFinder
3. Use cloud provider's SDK directly for advanced queries

## Phase 4: Component Migration (Week 2)

### 4.1 Complete FileManager Replacement

Since Glueful is not live, we perform a **complete replacement** without any deprecation period:

1. **Update all 14+ components** that use FileManager to use StorageManager
2. **Remove FileManager class** entirely from codebase
3. **Remove symfony/filesystem** from composer.json

### 4.2 Cache Driver Example

```php
// BEFORE: Using FileManager (src/Cache/Drivers/FileCacheDriver.php)
class FileCacheDriver {
    private FileManager $fileManager;  // REMOVED
    private FileFinder $fileFinder;    // STAYS THE SAME

    public function set(string $key, mixed $value): void {
        $this->fileManager->writeFile($path, serialize($value), 0600);
    }

    public function cleanup(int $olderThan): void {
        $oldFiles = $this->fileFinder->findCacheFiles(
            $this->directory,
            '*.cache',
            "{$olderThan} seconds ago"
        );

        foreach ($oldFiles as $file) {
            $this->fileManager->remove($file->getPathname());
        }
    }
}

// AFTER: Direct replacement with StorageManager
class FileCacheDriver {
    private StorageManager $storage;
    private FileFinder $fileFinder;  // UNCHANGED - still uses Symfony Finder

    public function set(string $key, mixed $value): void {
        $this->storage->disk('cache')->write($path, serialize($value));
        $this->storage->disk('cache')->setVisibility($path, Visibility::PRIVATE);
    }

    public function cleanup(int $olderThan): void {
        // FileFinder UNCHANGED - still provides advanced discovery
        $oldFiles = $this->fileFinder->findCacheFiles(
            $this->directory,
            '*.cache',
            "{$olderThan} seconds ago"
        );

        foreach ($oldFiles as $file) {
            // Only the deletion changes to use StorageManager
            $this->storage->disk('cache')->delete($file->getPathname());
        }
    }
}
```

### 4.2 Migration Manager Example

```php
// The discovery stays exactly the same
$migrations = $this->fileFinder->findMigrations($this->migrationsPath);

// Only file operations change
// OLD:
$content = $this->fileManager->readFile($migration->getPathname());

// NEW:
$content = $this->storage->disk()->read($migration->getPathname());
```

### 4.3 Uploader System Migration

The Uploader system currently has its own storage abstraction with S3Storage using `aws/aws-sdk-php`. This will be unified with StorageManager:

```php
// OLD: src/Uploader/Storage/S3Storage.php
use Aws\S3\S3Client;

class S3Storage implements StorageInterface {
    private S3Client $client;

    public function store(string $sourcePath, string $destinationPath): string {
        $this->client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $destinationPath,
            'SourceFile' => $sourcePath,
        ]);
        return $destinationPath;
    }
}

// NEW: Unified with StorageManager
class FileUploader {
    private string $disk = 'local';

    public function __construct(
        private StorageManager $storage
    ) {}

    public function upload(UploadedFile $file): string {
        $path = $this->generatePath($file);

        // Use StorageManager's stream handling
        $stream = fopen($file->getRealPath(), 'r');
        $this->storage->putStream($path, $stream, $this->disk);

        return $path;
    }

    public function setDisk(string $disk): self {
        // For S3, user must install: composer require league/flysystem-aws-s3-v3
        $this->disk = $disk;
        return $this;
    }

    public function getUrl(string $path): string {
        if ($this->disk === 's3') {
            // Check if S3 is available
            if (!$this->storage->diskExists('s3')) {
                throw new \RuntimeException('S3 disk not configured. Install league/flysystem-aws-s3-v3');
            }

            // Note: temporaryUrl() is adapter-specific
            // Supported by: S3, Azure Blob Storage, Google Cloud Storage
            // NOT supported by: Local, FTP, SFTP
            try {
                return $this->storage->disk('s3')->temporaryUrl($path, now()->addHour());
            } catch (\BadMethodCallException $e) {
                // Fallback if adapter doesn't support temporary URLs
                throw new \RuntimeException('Temporary URLs not supported by this storage adapter');
            }
        }

        // Local disk - return public path
        if ($this->disk === 'public') {
            return config('app.url') . '/storage/' . $path;
        }

        return $path;
    }
}
```

**Benefits of Uploader Unification:**
- Removes heavy `aws/aws-sdk-php` dependency (300MB+ vs 30MB for Flysystem adapter)
- Single storage abstraction for entire framework
- S3 becomes optional - only installed when needed
- Consistent API across all file operations

## Phase 5: Exception Handling

### 5.1 Simplified Approach: Single Exception + Classifier

Instead of introducing a full exception hierarchy, Glueful uses a single `StorageException` that wraps Flysystem exceptions and embeds a lightweight, stable classification in the message. This keeps the surface area small while enabling clean logs and HTTP mapping.

Key pieces:
- `StorageException::fromFlysystem()` wraps the original and prefixes the message with `[reason=... http=...]`.
- `ExceptionClassifier` converts Flysystem exceptions into a small set of reason codes and suggested HTTP status codes.
- Controllers can parse the prefix if they need structured data.

Example message format:
```
[reason=io_write_failed http=500] Unable to write file (path: cache/xyz.cache)
```

Usage in controllers:
```php
use Glueful\Storage\Exceptions\StorageException;
use Glueful\Storage\Support\ExceptionClassifier;

try {
    $storage->putJson('config/app.json', $data);
} catch (StorageException $e) {
    // Log with structured fields
    $parsed = ExceptionClassifier::parseFromMessage($e->getMessage());
    logger()->error('storage_error', [
        'reason' => $parsed['reason'],
        'http_status' => $parsed['http_status'],
        'message' => $e->getMessage(),
    ]);

    // Optional: map to HTTP
    $status = $parsed['http_status'] ?? 500;
    return response(['error' => $parsed['reason'] ?? 'storage_error'], $status);
}
```

Rationale:
- Keeps Flysystem as the underlying source of truth without coupling upper layers to its types.
- Provides consistent error categories for logs/metrics.
- Avoids maintaining a parallel exception tree unless a stronger contract is needed later.

## Phase 6: Testing Strategy (Week 3)

### 6.1 Test Structure

```
tests/
├── Unit/
│   ├── Storage/
│   │   ├── StorageManagerTest.php
│   │   ├── PathGuardTest.php
│   │   └── ExceptionMappingTest.php
│   └── Services/
│       └── FileFinderTest.php  # Unchanged - still tests Symfony Finder
├── Integration/
│   ├── Storage/
│   │   ├── DiskOperationsTest.php
│   │   ├── StreamingTest.php
│   │   └── CloudStorageTest.php
│   └── Discovery/
│       └── FileFinderIntegrationTest.php  # Unchanged
└── Property/
    └── PathGuardPropertyTest.php
```

### 6.2 Testing Approach

```php
class StorageManagerTest extends TestCase
{
    private StorageManager $storage;

    protected function setUp(): void
    {
        $config = [
            'default' => 'memory',
            'disks' => [
                'memory' => ['driver' => 'memory'],
            ],
        ];

        $this->storage = new StorageManager($config, new PathGuard());
    }

    public function testStorageOperations(): void
    {
        // Test storage I/O
        $this->storage->putJson('test.json', ['key' => 'value']);
        $result = $this->storage->getJson('test.json');
        $this->assertEquals(['key' => 'value'], $result);
    }
}

class FileFinderTest extends TestCase
{
    public function testFinderStillWorks(): void
    {
        // FileFinder tests remain unchanged
        $finder = new FileFinder();
        $migrations = $finder->findMigrations('/path/to/migrations');

        // Still uses Symfony Finder internally
        $this->assertInstanceOf(\Iterator::class, $migrations);
    }
}
```

## Key Decisions

### What We're NOT Building

1. **No SimpleFinder** - Not needed, can't replace Symfony Finder's capabilities
2. **No Discovery Abstraction** - Symfony Finder for local, Flysystem listContents() for cloud
3. **No Backward Compatibility Layer** - Direct replacement, FileManager completely removed

### Clear Boundaries

| Use Case | Solution |
|----------|----------|
| Local file discovery with filters | FileFinder (Symfony Finder) - unchanged |
| Cloud file listing | StorageManager->disk('s3')->listContents() |
| All file I/O operations | StorageManager (Flysystem) |
| Path validation | PathGuard |

## Migration Order

1. **Week 1**: Implement StorageManager, PathGuard, exceptions
2. **Week 2**: Replace all FileManager usage with StorageManager
3. **Week 3**: Remove FileManager class entirely, complete testing
4. **Week 4**: Documentation and final cleanup

## Success Metrics

- ✅ All file I/O operations use Flysystem
- ✅ FileFinder continues to work unchanged for discovery
- ✅ No feature loss in discovery capabilities
- ✅ Cloud storage support for basic operations
- ✅ Clean separation of concerns

## Documentation Requirements

### Developer Guide

```php
// Storage Operations - Use StorageManager
$storage = app('storage');
$storage->disk()->write('file.txt', 'content');
$storage->putJson('data.json', $data);
$storage->putStream('large.zip', $stream);

// Check if disk exists before using cloud features
if ($storage->diskExists('s3')) {
    $storage->disk('s3')->write('backup.sql', $content);
}

// Discovery Operations - Use FileFinder (unchanged)
$finder = app(FileFinder::class);
$oldLogs = $finder->findLogFiles($path, '7 days ago');
$migrations = $finder->findMigrations($path);

// Cloud Storage - Basic listing only
$s3Files = $storage->disk('s3')->listContents('uploads', true);
// Note: No date filtering, depth control, or custom filters on cloud

// **Temporary URLs - ONLY supported by S3, Azure, and GCS adapters**
// **For all other disks, use public URLs or signed routes**
if ($storage->diskExists('s3')) {
    $url = $storage->temporaryUrl('private/document.pdf', now()->addHour(), 's3');
} else {
    // Fallback: Use application-level signed routes
    $url = route('files.download', ['path' => 'document.pdf', 'signature' => $signature]);
}
```

## Conclusion

This migration provides Glueful with modern storage capabilities through Flysystem while preserving the sophisticated discovery features of Symfony Finder. By maintaining a clear separation between storage operations and file discovery, we achieve cloud storage support without sacrificing functionality.

The key insight: **Storage and Discovery are different concerns** - Flysystem excels at the former, Symfony Finder at the latter. This plan respects that distinction.
