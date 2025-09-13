# File Uploads (Local and S3)

This guide shows how to accept uploads securely, store them locally or on S3, and return URLs to clients using the built‑in `Glueful\Uploader` utilities.

## Overview

- Uploader class: `Glueful\Uploader\FileUploader`
- Storage backends via `Glueful\Uploader\Storage\StorageInterface`:
  - Local: `LocalStorage` (default)
  - Amazon S3: `S3Storage` (when `services.storage.driver = s3`)
- Safety: max file size, extension + MIME validation, basic hazard scanning, checksum utility
- Utilities: directory statistics and cleanup

## Configuration

### Local paths
- Upload root: `config('app.paths.uploads')`
- CDN base URL: `config('app.paths.cdn')`

### File limits and validation
- Max size: `config('filesystem.security.max_upload_size')`
  - Env: `MAX_FILE_UPLOAD_SIZE` (bytes). Example: `10485760` (10MB)
- Allowed extensions: `config('filesystem.file_manager.allowed_extensions')`
  - Env: `FILE_ALLOWED_EXTENSIONS="jpg,jpeg,png,gif,pdf,doc,docx,txt"`
- Hazard scanning: `config('filesystem.security.scan_uploads', true)`
  - Looks for code markers in the first 64KB (basic safeguard)

### Amazon S3
- Select backend: set `STORAGE_DRIVER=s3`
- Required env:
  - `S3_ACCESS_KEY_ID`, `S3_SECRET_ACCESS_KEY`, `S3_REGION`, `S3_BUCKET`
  - Optional: `S3_ENDPOINT` (for S3‑compatible hosts)
- ACL and URL behavior:
  - `S3_ACL` (default `private`)
  - `S3_SIGNED_URLS` (default `true`)
  - `S3_SIGNED_URL_TTL` (default `3600` seconds)
- Mapping: these envs populate `config('services.storage.s3.*')`

## Handling browser form uploads

```php
use Glueful\Uploader\FileUploader;
use Symfony\Component\HttpFoundation\Request;

class UploadController
{
    public function store(Request $request)
    {
        // Acquire uploader (via container or direct instantiation)
        $uploader = new FileUploader();

        // Token + params are application‑defined (example shows query params)
        $token = (string) $request->headers->get('X-Upload-Token', '');
        $getParams = $request->query->all();

        // For simplicity, pass native PHP files array if available
        // If using Symfony UploadedFile objects, convert to arrays (see below)
        $fileParams = $_FILES;

        $result = $uploader->handleUpload($token, $getParams, $fileParams);

        if (isset($result['error'])) {
            return response()->json($result, $result['code'] ?? 400);
        }

        return response()->json([
            'uuid' => $result['uuid'],
            'url'  => $result['url'],
        ]);
    }
}
```

Symfony `UploadedFile` support: you can pass either native `$_FILES` arrays or `UploadedFile` instances directly; the uploader normalizes them internally.

## Base64 uploads

When clients send base64 strings (e.g., mobile apps):

```php
$base64 = (string) $request->request->get('file_base64', '');
$tempPath = $uploader->handleBase64Upload($base64);

// Build a synthetic file array for the same flow
$mime = mime_content_type($tempPath) ?: 'application/octet-stream';
$synthetic = [
    'name' => 'upload.' . ($mime === 'image/png' ? 'png' : 'bin'),
    'type' => $mime,
    'tmp_name' => $tempPath,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($tempPath) ?: 0,
];

$result = $uploader->handleUpload($token, $getParams, $synthetic);
```

Note: `handleBase64Upload` writes to a temporary file and returns its path. The example above passes it through the same `handleUpload` pipeline so storage/validation logic is reused.

## Directory utilities

- Stats (count, size by type):

```php
$stats = $uploader->getDirectoryStats(config('app.paths.uploads'));
// ['exists' => true, 'total_files' => 42, 'total_size' => 123456, 'file_types' => [...]]
```

- Cleanup old files (age in seconds):

```php
$cleanup = $uploader->cleanupOldFiles(config('app.paths.uploads'), 86400); // 24h
// ['deleted_files' => N, 'freed_space' => bytes, 'freed_space_human' => '...']
```

## Security notes

- Enforce limits and lists:
  - Set `MAX_FILE_UPLOAD_SIZE` and `FILE_ALLOWED_EXTENSIONS` in `.env`.
- Keep `S3_ACL=private` with `S3_SIGNED_URLS=true` in production unless public distribution is intentional.
- Consider virus scanning hooks for stricter environments (the built‑in scan is a lightweight heuristic).

## Customization

- Storage driver
  - Prefer configuring via `STORAGE_DRIVER` (`local` or `s3`).
  - You can explicitly pass a driver string to `new FileUploader(storageDriver: 's3')` for one‑off cases.
- Blob persistence
  - `FileUploader` writes a record via `Glueful\Repository\BlobRepository` (resolved from the container). You can override or extend this repository in your application container.
- Filenames
  - Filenames are randomly generated with a timestamp; when no extension is provided, the uploader derives one from the detected MIME where possible.

## Troubleshooting

- “Invalid file type”: ensure extension and MIME are allowed (both are validated).
- “File size exceeds limit”: increase `MAX_FILE_UPLOAD_SIZE` (bytes) and confirm PHP `upload_max_filesize`/`post_max_size` are sufficient.
- S3 URL is signed instead of public: set `S3_ACL=public-read` and/or `S3_SIGNED_URLS=false` intentionally for public assets.

