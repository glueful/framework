# Storage Cookbook

This guide covers the framework's storage layer: configuring disks, reading/writing files, safe path handling, URL generation, and error handling.

## Overview

- Storage core: `Glueful\Storage\StorageManager` wraps Flysystem and manages multiple disks based on `config/storage.php`.
- Path safety: `Glueful\Storage\PathGuard` validates and normalizes paths (prevents traversal, null bytes, and disallows absolute paths by default).
- URLs: `Glueful\Storage\Support\UrlGenerator` formats public URLs using disk `base_url`/`cdn_base_url`.
- Errors: `Glueful\Storage\Exceptions\StorageException` wraps Flysystem exceptions with machine‑readable reason + suggested HTTP status.
- DI: Provided via the StorageProvider with a string alias `storage`.

## Configuration

Edit `config/storage.php` to define your disks.

### Basic Configuration

```php
return [
    'default' => env('STORAGE_DEFAULT_DISK', 'uploads'),

    // PathGuard configuration
    'path_guard' => [
        'allow_absolute' => false,      // Allow absolute paths
        'max_path_length' => 4096,      // Maximum path length
        'forbidden_patterns' => ['..', "\0"], // Patterns to reject
    ],

    'disks' => [
        // Local filesystem
        'uploads' => [
            'driver' => 'local',
            'root' => config('app.paths.uploads'),
            'visibility' => 'private', // 'private' or 'public'
            'base_url' => config('app.paths.cdn'), // Public URL base
        ],

        // In-memory filesystem (great for testing)
        'memory' => [
            'driver' => 'memory',
        ],

        // S3-compatible storage
        's3' => [
            'driver' => 's3',
            'key' => env('S3_ACCESS_KEY_ID'),
            'secret' => env('S3_SECRET_ACCESS_KEY'),
            'region' => env('S3_REGION', 'us-east-1'),
            'bucket' => env('S3_BUCKET'),
            'prefix' => env('S3_PREFIX', ''), // Optional path prefix
            'endpoint' => env('S3_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'cdn_base_url' => env('S3_CDN_BASE_URL'),
        ],

        // Azure Blob Storage
        'azure' => [
            'driver' => 'azure',
            'connection_string' => env('AZURE_CONNECTION_STRING'),
            'container' => env('AZURE_CONTAINER'),
            'prefix' => env('AZURE_PREFIX', ''),
            // Alternative: provide a prebuilt adapter
            // 'adapter' => $customAzureAdapter,
        ],

        // Google Cloud Storage
        'gcs' => [
            'driver' => 'gcs',
            'key_file' => env('GCS_KEY_FILE'),
            'project_id' => env('GCS_PROJECT_ID'),
            'bucket' => env('GCS_BUCKET'),
            'prefix' => env('GCS_PREFIX', ''),
        ],
    ],
];
```

Supported drivers: `local`, `memory`, `s3`, `azure`, `gcs`.
- Cloud drivers require their Flysystem adapter packages. If missing, `StorageManager` throws an instructive error.

## Dependency Injection

Resolve services:

```php
use Glueful\Storage\{StorageManager, PathGuard};
use Glueful\Storage\Support\UrlGenerator;

$storage = app(StorageManager::class);      // or app('storage')
$guard   = app(PathGuard::class);          // Path validation service
$urls    = app(UrlGenerator::class);       // URL generator service
```

The provider also binds a convenient string alias: `app('storage')`.

## Common Tasks

### Choose a disk

```php
$fs = app(StorageManager::class)->disk();        // default disk
$s3 = app(StorageManager::class)->disk('s3');    // named disk

if (!app(StorageManager::class)->diskExists('s3')) {
    // Log or fall back to default
}
```

### Native Flysystem Operations

The `disk()` method returns a Flysystem `FilesystemOperator`, giving you access to all native Flysystem methods:

```php
$disk = app(StorageManager::class)->disk();

// File operations
if ($disk->exists('path/to/file.txt')) {
    $content = $disk->read('path/to/file.txt');
    $disk->write('backup/file.txt', $content);
    $disk->delete('path/to/file.txt');
}

// Copy and move files
$disk->copy('source.txt', 'destination.txt');
$disk->move('old-path.txt', 'new-path.txt');

// Directory operations
$disk->createDirectory('logs/2025');
$disk->deleteDirectory('temp');

// File metadata
$size = $disk->fileSize('document.pdf');           // bytes
$modified = $disk->lastModified('document.pdf');   // timestamp
$mime = $disk->mimeType('image.jpg');              // MIME type

// Visibility (permissions)
$disk->setVisibility('public/file.txt', 'public');
$visibility = $disk->visibility('public/file.txt'); // 'public' or 'private'

// Stream operations
$stream = fopen('large-file.dat', 'r');
$disk->writeStream('uploads/large.dat', $stream);
$readStream = $disk->readStream('uploads/large.dat');
```

### Write and read JSON

```php
$storage = app(StorageManager::class);

$storage->putJson('reports/daily.json', [
    'generated_at' => date(DATE_ATOM),
    'items' => [1, 2, 3],
]);

$data = $storage->getJson('reports/daily.json');
```

### Stream large uploads atomically

```php
$fp = fopen('/path/to/bigfile', 'rb');
app(StorageManager::class)->putStream('uploads/big.dat', $fp);
```

This uses a temporary file strategy: writes to a `.tmp` file first, then atomically moves it into place. The temp file is cleaned up on failure.

### List contents

```php
foreach (app(StorageManager::class)->listContents('backups', true) as $entry) {
    // $entry is a StorageAttributes instance
    $path = $entry->path();
    $isFile = $entry->isFile();
    $isDir = $entry->isDir();
}
```

### Generate public URLs

```php
$urlGen = app(Glueful\Storage\Support\UrlGenerator::class);

// Generate URL for a file
$url = $urlGen->url('images/logo.png');
// Uses disk base_url/cdn_base_url when configured, else returns the path

// Get disk configuration (useful for signed URLs)
$diskConfig = $urlGen->diskConfig('s3'); // Returns array of disk config
```

## Helper Functions

```php
// Get absolute path to storage directory
$path = storage_path();                    // /path/to/project/storage
$path = storage_path('logs/app.log');      // /path/to/project/storage/logs/app.log
```

## Path Safety

`PathGuard` enforces security rules on file paths:

### Default Configuration

```php
// config/storage.php
'path_guard' => [
    'allow_absolute' => false,              // Reject absolute paths like /etc/passwd
    'max_path_length' => 4096,              // Maximum allowed path length
    'forbidden_patterns' => ['..', "\0"],   // Dangerous patterns to reject
]
```

### Path Validation Rules

- No null bytes (`\0`) - prevents string termination attacks
- No `..` traversal - prevents directory traversal attacks
- Normalized separators - converts backslashes to forward slashes
- Removes redundant `./` segments
- Absolute paths rejected unless explicitly allowed
- Path length limits enforced

### Manual Validation

```php
$guard = app(Glueful\Storage\PathGuard::class);

try {
    // Validates and normalizes the path
    $safe = $guard->validate('reports/2025/../2025/summary.json');
    // Returns: 'reports/2025/summary.json' (normalized)
} catch (\InvalidArgumentException $e) {
    // Path validation failed
}
```

## Error Handling

Most `StorageManager` operations convert Flysystem exceptions to `StorageException` with useful metadata:

### Basic Error Handling

```php
use Glueful\Storage\Exceptions\StorageException;

try {
    app(StorageManager::class)->getJson('missing/file.json');
} catch (StorageException $e) {
    $reason = $e->reason();      // Machine-readable reason code
    $status = $e->httpStatus();  // Suggested HTTP status code

    // Log with structured data
    logger()->error('Storage operation failed', [
        'reason' => $reason,
        'status' => $status,
        'message' => $e->getMessage()
    ]);
}
```

### Complete Error Reason Codes

| Reason Code | HTTP Status | Description |
|------------|-------------|-------------|
| `io_read_failed` | 404 | Unable to read file |
| `io_write_failed` | 500 | Unable to write file |
| `io_delete_failed` | 500 | Unable to delete file |
| `io_move_failed` | 500 | Unable to move/rename file |
| `io_copy_failed` | 500 | Unable to copy file |
| `dir_create_failed` | 500 | Unable to create directory |
| `dir_delete_failed` | 500 | Unable to delete directory |
| `existence_check_failed` | 500 | Unable to check if file exists |
| `metadata_retrieve_failed` | 500 | Unable to get file metadata |
| `visibility_set_failed` | 403 | Unable to set permissions |
| `list_failed` | 500 | Unable to list directory contents |
| `unknown_error` | 500 | Unclassified error |

### Parsing Exception Messages

```php
use Glueful\Storage\Support\ExceptionClassifier;

// Parse structured data from exception message
$parsed = ExceptionClassifier::parseFromMessage($e->getMessage());
// Returns: ['reason' => 'io_read_failed', 'http_status' => 404]
```

## Advanced Usage

### Custom Disk at Runtime

```php
$disk = app(StorageManager::class)->disk('uploads');

// All Flysystem methods available
$disk->write('file.txt', 'content');
$disk->setVisibility('file.txt', 'public');

// Get underlying adapter for advanced operations
if ($disk instanceof \League\Flysystem\Filesystem) {
    $adapter = $disk->getAdapter();
    // Adapter-specific operations
}
```

### Working with Streams

```php
$disk = app(StorageManager::class)->disk();

// Read large file as stream
$stream = $disk->readStream('large-file.zip');
while (!feof($stream)) {
    $chunk = fread($stream, 8192);
    // Process chunk
}
fclose($stream);

// Write from stream
$input = fopen('php://input', 'r');
$disk->writeStream('uploads/posted.dat', $input);
```

### Atomic Operations

```php
// StorageManager::putStream() is atomic by default
$fp = fopen('critical-data.json', 'r');
app(StorageManager::class)->putStream('config/settings.json', $fp);
// Writes to temp file first, then moves atomically
```

## Testing Tips

### Memory Driver for Tests

```php
// config/storage.php (testing environment)
'testing' => [
    'driver' => 'memory',
]
```

```php
// In your test
$storage = app(StorageManager::class)->disk('testing');
$storage->write('test.txt', 'content');
// No actual filesystem writes!
```

### Local Temp Directory

```php
// In test setup
$tempDir = sys_get_temp_dir() . '/test-' . uniqid();
mkdir($tempDir);
config(['storage.disks.test.root' => $tempDir]);

// In test teardown
$disk = app(StorageManager::class)->disk('test');
$disk->deleteDirectory('/');
rmdir($tempDir);
```

### URL Testing

```php
// Set test URLs
config(['storage.disks.uploads.base_url' => 'https://cdn.test']);

$url = app(UrlGenerator::class)->url('image.jpg', 'uploads');
$this->assertEquals('https://cdn.test/image.jpg', $url);
```

## Reference

### Core Classes

- `Glueful\Storage\StorageManager`
  - `disk(?string $name = null): FilesystemOperator` - Get disk instance
  - `diskExists(string $name): bool` - Check if disk configured
  - `putJson(string $path, mixed $data, ?string $disk = null): void`
  - `getJson(string $path, ?string $disk = null): mixed`
  - `putStream(string $path, $stream, ?string $disk = null): void` - Atomic write
  - `listContents(string $path, bool $recursive = false, ?string $disk = null): iterable`

- `Glueful\Storage\PathGuard`
  - `validate(string $path): string` - Validate and normalize path

- `Glueful\Storage\Support\UrlGenerator`
  - `url(string $path, ?string $disk = null): string` - Generate public URL
  - `diskConfig(string $disk): array` - Get disk configuration

- `Glueful\Storage\Exceptions\StorageException`
  - `reason(): ?string` - Get error reason code
  - `httpStatus(): ?int` - Get suggested HTTP status
  - `fromFlysystem(FilesystemException $e, string $path = ''): self`

### Helper Functions

- `storage_path(string $path = ''): string` - Get storage directory path