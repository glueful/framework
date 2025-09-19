<?php

declare(strict_types=1);

namespace Glueful\Uploader;

use Glueful\Repository\BlobRepository;
use Glueful\Helpers\Utils;
use Glueful\Uploader\Storage\{StorageInterface, FlysystemStorage};
use Glueful\Uploader\UploadException;
use Glueful\Uploader\ValidationException;
use Glueful\Storage\StorageManager;
use Glueful\Storage\Support\UrlGenerator;

final class FileUploader
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    private const MAX_FILE_SIZE = 10485760; // 10MB (fallback if not configured)

    private StorageInterface $storage;
    private BlobRepository $blobRepository;

    public function __construct(
        private readonly string $uploadsDirectory = '',
        private readonly string $cdnBaseUrl = '',
        private readonly ?string $storageDriver = null
    ) {
        // Prefer DI for repository to allow overrides/testing
        $this->blobRepository = container()->get(BlobRepository::class);
        // Resolve shared storage services from the container
        $diskName = $this->storageDriver !== null && $this->storageDriver !== ''
            ? $this->storageDriver
            : (string) (config('storage.default') ?? 'uploads');

        try {
            /** @var StorageManager $storageManager */
            $storageManager = app(StorageManager::class);
            /** @var UrlGenerator $urlGenerator */
            $urlGenerator = app(UrlGenerator::class);
            $this->storage = new FlysystemStorage($storageManager, $urlGenerator, $diskName);
        } catch (\Throwable) {
            // Fallback: build from config if container not ready
            $storageConfig = (array) config('storage');
            if (isset($storageConfig['disks'][$diskName]) && is_array($storageConfig['disks'][$diskName])) {
                $diskCfg = $storageConfig['disks'][$diskName];
                if (($diskCfg['driver'] ?? null) === 'local') {
                    if ($this->uploadsDirectory !== '') {
                        $storageConfig['disks'][$diskName]['root'] = $this->uploadsDirectory;
                    }
                    if ($this->cdnBaseUrl !== '') {
                        $storageConfig['disks'][$diskName]['base_url'] = $this->cdnBaseUrl;
                    }
                } elseif (($diskCfg['driver'] ?? null) === 's3') {
                    if ($this->cdnBaseUrl !== '') {
                        $storageConfig['disks'][$diskName]['cdn_base_url'] = $this->cdnBaseUrl;
                    }
                }
            }

            $storageManager = new \Glueful\Storage\StorageManager($storageConfig, new \Glueful\Storage\PathGuard());
            $urlGenerator = new \Glueful\Storage\Support\UrlGenerator($storageConfig, new \Glueful\Storage\PathGuard());
            $this->storage = new FlysystemStorage($storageManager, $urlGenerator, $diskName);
        }
    }

    /**
     * @param array<string, mixed> $getParams
     * @param mixed $fileParams $_FILES-like array or Symfony UploadedFile instance
     * @return array<string, mixed>
     */
    public function handleUpload(string $token, array $getParams, mixed $fileParams): array
    {
        try {
            $this->validateRequest($token, $getParams, $fileParams);

            $file = $this->processUploadedFile($fileParams, $getParams['key'] ?? null);
            $mime = $this->detectMime($file['tmp_name']);
            $filename = $this->generateSecureFilename($file['name'], $mime);

            $this->validateFileContent($file, $mime);

            $uploadPath = $this->moveFile($file['tmp_name'], $filename);

            return $this->saveFileRecord($token, $getParams, $file, $filename);
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => 400];
        } catch (UploadException $e) {
            error_log("Upload error: " . $e->getMessage());
            return ['error' => 'Upload failed', 'code' => 500];
        }
    }

    /**
     * @param array<string, mixed> $getParams
     * @param array<string, mixed> $fileParams
     */
    private function validateRequest(string $token, array $getParams, array $fileParams): void
    {
        if (!isset($getParams['user_id']) || $getParams['user_id'] === '' || $token === '' || $fileParams === []) {
            throw new ValidationException('Missing required parameters');
        }
    }

    /**
     * Normalize and validate uploaded file input.
     * Accepts either an $_FILES-like array or a Symfony UploadedFile instance.
     *
     * @param mixed $fileParams
     * @return array<string, mixed>
     */
    private function processUploadedFile(mixed $fileParams, ?string $key): array
    {
        $raw = isset($key) ? ($fileParams[$key] ?? null) : $fileParams;
        $file = $this->normalizeFileInput($raw);

        if (
            !is_array($file) || !isset($file['tmp_name']) || $file['tmp_name'] === ''
            || !isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK
        ) {
            throw new UploadException('Invalid file upload');
        }

        $maxSize = (int) (config('filesystem.security.max_upload_size', self::MAX_FILE_SIZE));
        if ($file['size'] > $maxSize) {
            throw new ValidationException('File size exceeds limit');
        }

        return $file;
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>|null
     */
    private function normalizeFileInput(mixed $input): ?array
    {
        // Symfony UploadedFile support (avoid hard dependency by FQCN check)
        if ($input instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            return [
                'name' => $input->getClientOriginalName(),
                'type' => $input->getMimeType() ?? 'application/octet-stream',
                'tmp_name' => $input->getPathname(),
                'error' => UPLOAD_ERR_OK,
                'size' => $input->getSize(),
            ];
        }

        // Already in expected array shape
        if (is_array($input)) {
            if (isset($input['tmp_name']) || isset($input['name']) || isset($input['size'])) {
                return $input;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $file
     */
    private function validateFileContent(array $file, ?string $detectedMime = null): void
    {
        $mime = $detectedMime ?? $this->detectMime($file['tmp_name']);

        // Respect configured allowed extensions when provided
        $allowedExtensions = config('filesystem.file_manager.allowed_extensions');
        $originalExt = strtolower((string) pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));

        if (is_array($allowedExtensions) && $allowedExtensions !== []) {
            $allowedExtensions = array_map('strtolower', $allowedExtensions);
            if ($originalExt === '' || !in_array($originalExt, $allowedExtensions, true)) {
                throw new ValidationException('File extension not allowed');
            }

            $mimeMap = $this->validMimeMap();
            if (isset($mimeMap[$originalExt])) {
                if (!in_array($mime, $mimeMap[$originalExt], true)) {
                    throw new ValidationException('MIME type does not match file extension');
                }
            } else {
                // Unknown extension mapping: enforce generic allowed MIME list
                if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
                    throw new ValidationException('Invalid file type');
                }
            }
        } else {
            // Fallback: generic allowed MIME list
            if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
                throw new ValidationException('Invalid file type');
            }
        }

        // Additional security checks (configurable)
        $scanEnabled = (bool) config('filesystem.security.scan_uploads', true);
        if ($scanEnabled && $this->isFileHazardous($file['tmp_name'])) {
            throw new ValidationException('File content not allowed');
        }
    }

    private function isFileHazardous(string $filepath): bool
    {
        // Check for PHP code or other potentially harmful content in first 64KB
        $content = @file_get_contents($filepath, false, null, 0, 65536);
        if ($content === false) {
            $content = '';
        }
        return (
            str_contains($content, '<?php') ||
            str_contains($content, '<?=') ||
            str_contains($content, '<script')
        );
    }

    private function moveFile(string $tempPath, string $filename): string
    {
        return $this->storage->store($tempPath, $filename);
    }

    private function generateSecureFilename(string $originalName, ?string $mime = null): string
    {
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $sanitized = preg_replace('/[^a-zA-Z0-9_.-]/', '', $extension);

        // Derive from MIME if extension missing
        if (($sanitized === '' || $sanitized === null) && $mime !== null) {
            $inverse = $this->inverseMimeMap();
            $sanitized = $inverse[$mime] ?? '';
        }

        $base = sprintf('%s_%s', time(), bin2hex(random_bytes(8)));
        return $sanitized !== '' ? ($base . '.' . $sanitized) : $base;
    }

    /**
     * @param array<string, mixed> $getParams
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    private function saveFileRecord(string $token, array $getParams, array $file, string $filename): array
    {
        $user = Utils::getUser();
        $uuid = $user['uuid'] ?? null;

        $blobData = [
            'name' => $file['name'],
            'mime_type' => $file['type'],
            'url' => $filename,
            'created_by' => $uuid,
            'size' => $file['size'],
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s')
        ];

        $blobUuid = $this->blobRepository->create($blobData);

        return [
            'uuid' => $blobUuid,
            'url' => $this->storage->getUrl($filename)
        ];
    }

    public function handleBase64Upload(string $base64String): string
    {
        if ($base64String === '') {
            throw new ValidationException('Empty base64 string');
        }

        $tempFile = sprintf('/tmp/%s', bin2hex(random_bytes(16)));

        try {
            $data = base64_decode($base64String, true);
            if ($data === false) {
                throw new ValidationException('Invalid base64 string');
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
     * @param  string $directory Directory to analyze
     * @return array Usage statistics
     */
    /**
     * @return array<string, mixed>
     */
    public function getDirectoryStats(string $directory): array
    {
        if (!is_dir($directory)) {
            return [
                'exists' => false,
                'total_files' => 0,
                'total_size' => 0,
                'total_size_human' => '0 B'
            ];
        }

        $fileFinder = container()->get(\Glueful\Services\FileFinder::class);
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
            'directory' => $directory
        ];
    }

    /**
     * Clean up old files in upload directory
     *
     * @param  string $directory Directory to clean
     * @param  int    $maxAge    Maximum age in seconds
     * @return array Cleanup results
     */
    /**
     * @return array<string, mixed>
     */
    public function cleanupOldFiles(string $directory, int $maxAge = 86400): array
    {
        $fileFinder = container()->get(\Glueful\Services\FileFinder::class);
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
            'freed_space_human' => $this->formatBytes($totalSize)
        ];
    }

    /**
     * Validate file extension and MIME type
     *
     * @param  string $filePath File path to validate
     * @return bool True if valid
     */
    public function validateFileType(string $filePath): bool
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt'];

        if (!in_array($extension, $allowedExtensions, true)) {
            return false;
        }

        // Check MIME type matches extension
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        $validMimes = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'txt' => ['text/plain']
        ];

        return in_array($mimeType, $validMimes[$extension], true);
    }

    /**
     * Calculate file checksum
     *
     * @param  string $filePath File path
     * @return string SHA256 checksum
     */
    public function calculateChecksum(string $filePath): string
    {
        return hash_file('sha256', $filePath);
    }


    /**
     * Format bytes to human readable format
     *
     * @param  int $size Size in bytes
     * @return string Formatted size
     */
    private function formatBytes(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $size > 0 ? floor(log($size, 1024)) : 0;

        if ($power >= count($units)) {
            $power = count($units) - 1;
        }

        return round($size / (1024 ** $power), 2) . ' ' . $units[$power];
    }

    private function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mt = finfo_file($finfo, $path);
        $mimeType = is_string($mt) && $mt !== '' ? $mt : 'application/octet-stream';
        finfo_close($finfo);
        return $mimeType;
    }

    /**
     * @return array<string, array<string>>
     */
    private function validMimeMap(): array
    {
        return [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'txt' => ['text/plain']
        ];
    }

    /**
     * @return array<string, string>
     */
    private function inverseMimeMap(): array
    {
        $inverse = [];
        foreach ($this->validMimeMap() as $ext => $mimes) {
            foreach ($mimes as $m) {
                $inverse[$m] = $ext;
            }
        }
        return $inverse;
    }
}
