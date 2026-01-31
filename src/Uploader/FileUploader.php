<?php

declare(strict_types=1);

namespace Glueful\Uploader;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Repository\BlobRepository;
use Glueful\Helpers\Utils;
use Glueful\Uploader\Storage\StorageInterface;
use Glueful\Uploader\Storage\FlysystemStorage;
use Glueful\Validation\ValidationException;
use Glueful\Storage\StorageManager;
use Glueful\Storage\Support\UrlGenerator;

/**
 * Handles file uploads with validation, storage, and metadata tracking
 *
 * Supports both standard form uploads and media uploads with optional
 * thumbnail generation and metadata extraction.
 */
final class FileUploader
{
    /**
     * Default allowed MIME types (used when config is not set)
     */
    private const DEFAULT_ALLOWED_MIME_TYPES = [
        // Images
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        // Videos
        'video/mp4',
        'video/quicktime',
        'video/webm',
        'video/x-msvideo',
        // Audio
        'audio/mpeg',
        'audio/mp4',
        'audio/wav',
        'audio/ogg',
        // Documents
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private const MAX_FILE_SIZE = 10485760; // 10MB fallback

    private StorageInterface $storage;
    private BlobRepository $blobRepository;
    private ThumbnailGenerator $thumbnailGenerator;
    private MediaMetadataExtractor $metadataExtractor;
    private ?ApplicationContext $context;

    public function __construct(
        private readonly string $uploadsDirectory = '',
        private readonly string $cdnBaseUrl = '',
        private readonly ?string $storageDriver = null,
        ?ApplicationContext $context = null
    ) {
        $this->context = $context;
        $this->blobRepository = $this->getContainer()->get(BlobRepository::class);
        $this->storage = $this->initializeStorage();
        $this->thumbnailGenerator = new ThumbnailGenerator($this->storage, $this->context);
        $this->metadataExtractor = new MediaMetadataExtractor();
    }

    /**
     * Handle standard file upload with validation and storage
     *
     * @param string $token Upload token
     * @param array<string, mixed> $getParams Request parameters (must include user_id)
     * @param mixed $fileParams $_FILES-like array or Symfony UploadedFile
     * @return array<string, mixed> Upload result with uuid and url, or error
     */
    public function handleUpload(string $token, array $getParams, mixed $fileParams): array
    {
        try {
            $this->validateRequest($token, $getParams, $fileParams);

            $file = $this->processUploadedFile($fileParams, $getParams['key'] ?? null);
            $mime = $this->detectMime($file['tmp_name']);
            $filename = $this->generateSecureFilename($file['name'], $mime);

            $this->validateFileContent($file, $mime);
            $this->storage->store($file['tmp_name'], $filename);

            return $this->saveFileRecord($token, $getParams, $file, $filename);
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => 400];
        } catch (UploadException $e) {
            error_log("Upload error: " . $e->getMessage());
            return ['error' => 'Upload failed', 'code' => 500];
        }
    }

    /**
     * Upload a media file with optional thumbnail generation
     *
     * @param mixed $fileInput $_FILES array or Symfony UploadedFile
     * @param string $storagePath Storage path prefix (e.g., 'posts/uuid123')
     * @param array<string, mixed> $options Upload options:
     *   - generate_thumbnail: bool (default: true for images)
     *   - thumbnail_width: int (default: 400)
     *   - thumbnail_height: int (default: 400)
     *   - thumbnail_quality: int (default: 80)
     *   - save_to_blobs: bool (default: true)
     * @return array<string, mixed> Upload result with media metadata
     * @throws UploadException|ValidationException
     */
    public function uploadMedia(mixed $fileInput, string $storagePath = '', array $options = []): array
    {
        $file = $this->normalizeFileInput($fileInput);

        if ($file === null || !isset($file['tmp_name']) || $file['tmp_name'] === '') {
            throw new UploadException('Invalid file input');
        }

        // Validate
        $this->validateFileSize($file['size'] ?? 0);
        $mime = $this->detectMime($file['tmp_name']);
        $this->validateMimeType($mime);

        // Extract metadata
        $metadata = $this->metadataExtractor->extract($file['tmp_name'], $mime);

        // Store file
        $filename = $this->generateSecureFilename($file['name'] ?? 'upload', $mime);
        $fullPath = $this->buildPath($storagePath, $filename);
        $this->storage->store($file['tmp_name'], $fullPath);

        // Generate thumbnail if applicable
        $thumbUrl = $this->maybeGenerateThumbnail(
            $file['tmp_name'],
            $storagePath,
            $filename,
            $mime,
            $options
        );

        // Build result
        $result = [
            'type' => $metadata->type,
            'url' => $this->storage->getUrl($fullPath),
            'thumb_url' => $thumbUrl,
            'mime_type' => $mime,
            'size_bytes' => $file['size'] ?? 0,
            'width' => $metadata->width,
            'height' => $metadata->height,
            'duration_s' => $metadata->durationSeconds,
            'filename' => $filename,
            'path' => $fullPath,
        ];

        // Save to database if requested
        if ((bool) ($options['save_to_blobs'] ?? true)) {
            $result['blob_uuid'] = $this->saveBlobRecord($file, $mime, $fullPath);
        }

        return $result;
    }

    /**
     * Handle base64 encoded file upload
     *
     * @param string $base64String Base64 encoded file content
     * @return string Path to temporary file
     * @throws ValidationException|UploadException
     */
    public function handleBase64Upload(string $base64String): string
    {
        if ($base64String === '') {
            throw ValidationException::forField('file', 'Empty base64 string');
        }

        $tempFile = sprintf('/tmp/%s', bin2hex(random_bytes(16)));

        try {
            $data = base64_decode($base64String, true);
            if ($data === false) {
                throw ValidationException::forField('file', 'Invalid base64 string');
            }

            if (@file_put_contents($tempFile, $data) === false) {
                throw new UploadException('Failed to save temporary file');
            }

            return $tempFile;
        } catch (\Exception $e) {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            throw new UploadException('Base64 processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Get upload directory usage statistics
     *
     * @param string $directory Directory to analyze
     * @return array<string, mixed> Usage statistics
     */
    public function getDirectoryStats(string $directory): array
    {
        if (!is_dir($directory)) {
            return [
                'exists' => false,
                'total_files' => 0,
                'total_size' => 0,
                'total_size_human' => '0 B',
            ];
        }

        $fileFinder = $this->getContainer()->get(\Glueful\Services\FileFinder::class);
        $finder = $fileFinder->createFinder();
        $files = $finder->files()->in($directory);

        $totalFiles = 0;
        $totalSize = 0;
        $fileTypes = [];

        foreach ($files as $file) {
            $totalFiles++;
            $size = $file->getSize();
            $totalSize += $size;

            $extension = strtolower($file->getExtension());
            $fileTypes[$extension] = ($fileTypes[$extension] ?? 0) + 1;
        }

        return [
            'exists' => true,
            'total_files' => $totalFiles,
            'total_size' => $totalSize,
            'total_size_human' => $this->formatBytes($totalSize),
            'file_types' => $fileTypes,
            'directory' => $directory,
        ];
    }

    /**
     * Clean up old files in upload directory
     *
     * @param string $directory Directory to clean
     * @param int $maxAge Maximum age in seconds (default: 24 hours)
     * @return array<string, mixed> Cleanup results
     */
    public function cleanupOldFiles(string $directory, int $maxAge = 86400): array
    {
        $fileFinder = $this->getContainer()->get(\Glueful\Services\FileFinder::class);
        $finder = $fileFinder->createFinder();
        $cutoffTime = time() - $maxAge;

        $files = $finder->files()
            ->in($directory)
            ->date('< ' . date('Y-m-d H:i:s', $cutoffTime));

        $deleted = 0;
        $totalSize = 0;

        foreach ($files as $file) {
            $size = $file->getSize();
            if (@unlink($file->getPathname())) {
                $deleted++;
                $totalSize += $size;
            }
        }

        return [
            'deleted_files' => $deleted,
            'freed_space' => $totalSize,
            'freed_space_human' => $this->formatBytes($totalSize),
        ];
    }

    /**
     * Validate file extension and MIME type match
     */
    public function validateFileType(string $filePath): bool
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeMap = $this->getMimeMap();

        if (!isset($mimeMap[$extension])) {
            return false;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath);

        return in_array($mimeType, $mimeMap[$extension], true);
    }

    /**
     * Calculate file checksum
     */
    public function calculateChecksum(string $filePath): string
    {
        return hash_file('sha256', $filePath);
    }

    /**
     * Get the storage interface instance
     */
    public function getStorage(): StorageInterface
    {
        return $this->storage;
    }

    /**
     * Get the thumbnail generator instance
     */
    public function getThumbnailGenerator(): ThumbnailGenerator
    {
        return $this->thumbnailGenerator;
    }

    /**
     * Get the metadata extractor instance
     */
    public function getMetadataExtractor(): MediaMetadataExtractor
    {
        return $this->metadataExtractor;
    }

    // -------------------------------------------------------------------------
    // Private Methods
    // -------------------------------------------------------------------------

    private function initializeStorage(): StorageInterface
    {
        $diskName = $this->storageDriver !== null && $this->storageDriver !== ''
            ? $this->storageDriver
            : (string) ($this->getConfig('storage.default', 'uploads'));

        try {
            /** @var StorageManager $storageManager */
            $storageManager = app($this->resolveContext(), StorageManager::class);
            /** @var UrlGenerator $urlGenerator */
            $urlGenerator = app($this->resolveContext(), UrlGenerator::class);

            return new FlysystemStorage($storageManager, $urlGenerator, $diskName);
        } catch (\Throwable) {
            return $this->createFallbackStorage($diskName);
        }
    }

    private function createFallbackStorage(string $diskName): StorageInterface
    {
        $storageConfig = (array) $this->getConfig('storage', []);

        if (isset($storageConfig['disks'][$diskName]) && is_array($storageConfig['disks'][$diskName])) {
            $diskCfg = $storageConfig['disks'][$diskName];

            if (($diskCfg['driver'] ?? null) === 'local') {
                if ($this->uploadsDirectory !== '') {
                    $storageConfig['disks'][$diskName]['root'] = $this->uploadsDirectory;
                }
                if ($this->cdnBaseUrl !== '') {
                    $storageConfig['disks'][$diskName]['base_url'] = $this->cdnBaseUrl;
                }
            } elseif (($diskCfg['driver'] ?? null) === 's3' && $this->cdnBaseUrl !== '') {
                $storageConfig['disks'][$diskName]['cdn_base_url'] = $this->cdnBaseUrl;
            }
        }

        $storageManager = new StorageManager($storageConfig, new \Glueful\Storage\PathGuard());
        $urlGenerator = new UrlGenerator($storageConfig, new \Glueful\Storage\PathGuard());

        return new FlysystemStorage($storageManager, $urlGenerator, $diskName);
    }

    /**
     * @param array<string, mixed> $getParams
     * @param array<string, mixed> $fileParams
     */
    private function validateRequest(string $token, array $getParams, array $fileParams): void
    {
        if (
            !isset($getParams['user_id'])
            || $getParams['user_id'] === ''
            || $token === ''
            || $fileParams === []
        ) {
            throw ValidationException::forField('file', 'Missing required parameters');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function processUploadedFile(mixed $fileParams, ?string $key): array
    {
        $raw = isset($key) ? ($fileParams[$key] ?? null) : $fileParams;
        $file = $this->normalizeFileInput($raw);

        if (
            !is_array($file)
            || !isset($file['tmp_name'])
            || $file['tmp_name'] === ''
            || !isset($file['error'])
            || (int) $file['error'] !== UPLOAD_ERR_OK
        ) {
            throw new UploadException('Invalid file upload');
        }

        $this->validateFileSize($file['size'] ?? 0);

        return $file;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeFileInput(mixed $input): ?array
    {
        if ($input instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            return [
                'name' => $input->getClientOriginalName(),
                'type' => $input->getMimeType() ?? 'application/octet-stream',
                'tmp_name' => $input->getPathname(),
                'error' => UPLOAD_ERR_OK,
                'size' => $input->getSize(),
            ];
        }

        if (is_array($input)) {
            if (isset($input['tmp_name']) || isset($input['name']) || isset($input['size'])) {
                return $input;
            }
        }

        return null;
    }

    private function validateFileSize(int $size): void
    {
        $maxSize = (int) $this->getConfig('filesystem.security.max_upload_size', self::MAX_FILE_SIZE);

        if ($size > $maxSize) {
            throw ValidationException::forField('file', 'File size exceeds limit');
        }
    }

    private function validateMimeType(string $mime): void
    {
        $allowed = $this->getAllowedMimeTypes();

        if (!in_array($mime, $allowed, true)) {
            throw ValidationException::forField('file', 'File type not allowed: ' . $mime);
        }
    }

    /**
     * @param array<string, mixed> $file
     */
    private function validateFileContent(array $file, ?string $detectedMime = null): void
    {
        $mime = $detectedMime ?? $this->detectMime($file['tmp_name']);
        $allowedExtensions = $this->getConfig('filesystem.file_manager.allowed_extensions');
        $originalExt = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

        if (is_array($allowedExtensions) && $allowedExtensions !== []) {
            $allowedExtensions = array_map('strtolower', $allowedExtensions);

            if ($originalExt === '' || !in_array($originalExt, $allowedExtensions, true)) {
                throw ValidationException::forField('file', 'File extension not allowed');
            }

            $mimeMap = $this->getMimeMap();
            if (isset($mimeMap[$originalExt])) {
                if (!in_array($mime, $mimeMap[$originalExt], true)) {
                    throw ValidationException::forField('file', 'MIME type does not match file extension');
                }
            } elseif (!in_array($mime, self::DEFAULT_ALLOWED_MIME_TYPES, true)) {
                throw ValidationException::forField('file', 'Invalid file type');
            }
        } elseif (!in_array($mime, self::DEFAULT_ALLOWED_MIME_TYPES, true)) {
            throw ValidationException::forField('file', 'Invalid file type');
        }

        // Security scan
        $scanEnabled = (bool) $this->getConfig('filesystem.security.scan_uploads', true);
        if ($scanEnabled && $this->isFileHazardous($file['tmp_name'])) {
            throw ValidationException::forField('file', 'File content not allowed');
        }
    }

    private function isFileHazardous(string $filepath): bool
    {
        $content = @file_get_contents($filepath, false, null, 0, 65536);
        if ($content === false) {
            return false;
        }

        return str_contains($content, '<?php')
            || str_contains($content, '<?=')
            || str_contains($content, '<script');
    }

    private function detectMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mt = $finfo->file($path);

        return is_string($mt) && $mt !== '' ? $mt : 'application/octet-stream';
    }

    private function generateSecureFilename(string $originalName, ?string $mime = null): string
    {
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $sanitized = preg_replace('/[^a-zA-Z0-9_.-]/', '', $extension);

        if (($sanitized === '' || $sanitized === null) && $mime !== null) {
            $sanitized = $this->getExtensionFromMime($mime);
        }

        $base = sprintf('%s_%s', time(), bin2hex(random_bytes(8)));

        return $sanitized !== '' ? ($base . '.' . $sanitized) : $base;
    }

    private function buildPath(string $storagePath, string $filename): string
    {
        if ($storagePath === '') {
            return $filename;
        }

        return rtrim($storagePath, '/') . '/' . $filename;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function maybeGenerateThumbnail(
        string $sourcePath,
        string $storagePath,
        string $filename,
        string $mime,
        array $options
    ): ?string {
        // Check global config first
        $globalEnabled = (bool) $this->getConfig('filesystem.uploader.thumbnail_enabled', true);
        if (!$globalEnabled) {
            return null;
        }

        // Check per-upload option (defaults to true for supported formats)
        $shouldGenerate = (bool) ($options['generate_thumbnail']
            ?? $this->thumbnailGenerator->supports($mime));

        if (!$shouldGenerate || !$this->thumbnailGenerator->supports($mime)) {
            return null;
        }

        return $this->thumbnailGenerator->generate($sourcePath, $storagePath, $filename, [
            'width' => $options['thumbnail_width'] ?? null,
            'height' => $options['thumbnail_height'] ?? null,
            'quality' => $options['thumbnail_quality'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $getParams
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    private function saveFileRecord(string $token, array $getParams, array $file, string $filename): array
    {
        $user = Utils::getUser();

        $blobData = [
            'name' => $file['name'],
            'mime_type' => $file['type'],
            'url' => $filename,
            'created_by' => $user['uuid'] ?? null,
            'size' => $file['size'],
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $blobUuid = $this->blobRepository->create($blobData);

        return [
            'uuid' => $blobUuid,
            'url' => $this->storage->getUrl($filename),
        ];
    }

    /**
     * @param array<string, mixed> $file
     */
    private function saveBlobRecord(array $file, string $mime, string $path): string
    {
        $user = Utils::getUser();

        return $this->blobRepository->create([
            'name' => $file['name'] ?? basename($path),
            'mime_type' => $mime,
            'url' => $path,
            'created_by' => $user['uuid'] ?? null,
            'size' => $file['size'] ?? 0,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string>
     */
    private function getAllowedMimeTypes(): array
    {
        $configured = $this->getConfig('filesystem.uploader.allowed_mime_types');

        if (is_array($configured) && $configured !== []) {
            return $configured;
        }

        return self::DEFAULT_ALLOWED_MIME_TYPES;
    }

    /**
     * @return array<string, array<string>>
     */
    private function getMimeMap(): array
    {
        return [
            // Images
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            // Videos
            'mp4' => ['video/mp4'],
            'mov' => ['video/quicktime'],
            'webm' => ['video/webm'],
            'avi' => ['video/x-msvideo'],
            // Audio
            'mp3' => ['audio/mpeg'],
            'm4a' => ['audio/mp4'],
            'wav' => ['audio/wav'],
            'ogg' => ['audio/ogg'],
            // Documents
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'txt' => ['text/plain'],
        ];
    }

    private function getExtensionFromMime(string $mime): string
    {
        $mimeToExt = [];
        foreach ($this->getMimeMap() as $ext => $mimes) {
            foreach ($mimes as $m) {
                $mimeToExt[$m] = $ext;
            }
        }

        return $mimeToExt[$mime] ?? '';
    }

    private function formatBytes(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $size > 0 ? floor(log($size, 1024)) : 0;

        if ($power >= count($units)) {
            $power = count($units) - 1;
        }

        return round($size / (1024 ** $power), 2) . ' ' . $units[$power];
    }

    private function getConfig(string $key, mixed $default = null): mixed
    {
        if ($this->context === null) {
            return $default;
        }

        return config($this->context, $key, $default);
    }

    private function getContainer(): \Psr\Container\ContainerInterface
    {
        return container($this->resolveContext());
    }

    private function resolveContext(): ApplicationContext
    {
        if ($this->context === null) {
            throw new \RuntimeException('ApplicationContext is required for FileUploader.');
        }

        return $this->context;
    }
}
