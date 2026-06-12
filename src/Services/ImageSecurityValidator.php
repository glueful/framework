<?php

declare(strict_types=1);

namespace Glueful\Services;

use Glueful\Http\Exceptions\Domain\BusinessLogicException;

/**
 * Image Security Validator
 *
 * Validates image processing requests against security policies.
 * Prevents malicious image processing operations and enforces limits.
 */
class ImageSecurityValidator
{
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Validate image source URL
     *
     * @param string $url Image URL to validate
     * @return bool True if valid
     * @throws BusinessLogicException If URL is not allowed
     */
    public function validateUrl(string $url): bool
    {
        // Check if external URLs are disabled
        if (($this->config['disable_external_urls'] ?? false) === true && $this->isExternalUrl($url)) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'External image URLs are disabled'
            );
        }

        // Validate against allowed domains
        if (!$this->isDomainAllowed($url)) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Domain not in allowed list'
            );
        }

        // Check for suspicious URLs
        if ($this->isSuspiciousUrl($url)) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Suspicious URL detected'
            );
        }

        return true;
    }

    /**
     * Validate image format and MIME type
     *
     * @param string $format File format/extension
     * @param string|null $mimeType MIME type from file
     * @return bool True if valid
     * @throws BusinessLogicException If format is not allowed
     */
    public function validateFormat(string $format, ?string $mimeType = null): bool
    {
        $format = strtolower(trim($format, '.'));

        // Check allowed formats
        if (!in_array($format, $this->config['allowed_formats'], true)) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                "Format '{$format}' is not allowed"
            );
        }

        // Validate MIME type if provided and validation is enabled
        if ($mimeType !== null && $mimeType !== '' && ($this->config['validate_mime'] ?? false) === true) {
            if (!in_array($mimeType, $this->config['allowed_mime_types'], true)) {
                throw BusinessLogicException::operationNotAllowed(
                    'image_processing',
                    "MIME type '{$mimeType}' is not allowed"
                );
            }

            // Check format/MIME consistency
            if (!$this->isFormatMimeConsistent($format, $mimeType)) {
                throw BusinessLogicException::operationNotAllowed(
                    'image_processing',
                    'Format and MIME type mismatch'
                );
            }
        }

        return true;
    }

    /**
     * Validate an image file before handing it to an image decoder.
     *
     * This uses server-detected MIME and getimagesize() dimensions so callers do
     * not have to trust client-provided MIME metadata or decode the image before
     * enforcing dimensional limits.
     *
     * @throws BusinessLogicException If the file is not an allowed image
     */
    public function validateImageFile(string $path, ?string $format = null): bool
    {
        if (!is_file($path)) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Image file does not exist'
            );
        }

        $format ??= strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $mimeType = $this->detectMime($path);

        $this->validateFormat($format, $mimeType);

        $dimensions = @getimagesize($path);
        if ($dimensions === false) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Invalid image file'
            );
        }

        return $this->validateDimensions($dimensions[0], $dimensions[1]);
    }

    /**
     * Validate image dimensions
     *
     * @param int $width Image width
     * @param int $height Image height
     * @return bool True if valid
     * @throws BusinessLogicException If dimensions exceed limits
     */
    public function validateDimensions(int $width, int $height): bool
    {
        if ($width > $this->config['max_width']) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                "Width {$width}px exceeds limit of {$this->config['max_width']}px"
            );
        }

        if ($height > $this->config['max_height']) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                "Height {$height}px exceeds limit of {$this->config['max_height']}px"
            );
        }

        // Check total pixel count to prevent memory bombs
        $totalPixels = $width * $height;
        $maxPixels = $this->config['max_width'] * $this->config['max_height'];

        if ($totalPixels > $maxPixels) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Image dimensions too large (memory limit protection)'
            );
        }

        return true;
    }

    /**
     * Validate file size
     *
     * @param int $fileSize File size in bytes
     * @return bool True if valid
     * @throws BusinessLogicException If file is too large
     */
    public function validateFileSize(int $fileSize): bool
    {
        $maxSize = $this->parseSize($this->config['max_filesize']);

        if ($fileSize > $maxSize) {
            $maxHuman = $this->formatBytes($maxSize);
            $actualHuman = $this->formatBytes($fileSize);

            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                "File size {$actualHuman} exceeds limit of {$maxHuman}"
            );
        }

        return true;
    }

    /**
     * Validate image quality parameter
     *
     * @param int $quality Quality value (0-100)
     * @return bool True if valid
     * @throws BusinessLogicException If quality is invalid
     */
    public function validateQuality(int $quality): bool
    {
        if ($quality < 1 || $quality > 100) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Quality must be between 1 and 100'
            );
        }

        return true;
    }

    /**
     * Check if URL is external
     *
     * @param string $url URL to check
     * @return bool True if external
     */
    private function isExternalUrl(string $url): bool
    {
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));

        return $scheme === 'http' || $scheme === 'https';
    }

    /**
     * Check if domain is in allowed list
     *
     * @param string $url URL to check
     * @return bool True if allowed
     */
    private function isDomainAllowed(string $url): bool
    {
        $allowedDomains = $this->config['allowed_domains'];

        // Allow all domains if * is specified
        if (in_array('*', $allowedDomains, true)) {
            return true;
        }

        // For local files, always allow
        if (!$this->isExternalUrl($url)) {
            return true;
        }

        $parsedUrl = parse_url($url);
        if (!$parsedUrl || !isset($parsedUrl['host'])) {
            return false;
        }

        $host = strtolower($parsedUrl['host']);

        // Check exact domain matches and wildcard subdomains
        foreach ($allowedDomains as $allowedDomain) {
            $allowedDomain = strtolower(trim($allowedDomain));

            // Exact match
            if ($host === $allowedDomain) {
                return true;
            }

            // Wildcard subdomain match (*.example.com)
            if (str_starts_with($allowedDomain, '*.')) {
                $baseDomain = substr($allowedDomain, 2);
                if (str_ends_with($host, '.' . $baseDomain) || $host === $baseDomain) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check for suspicious URL patterns
     *
     * @param string $url URL to check
     * @return bool True if suspicious
     */
    private function isSuspiciousUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return true;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== '' && !in_array($scheme, ['http', 'https'], true)) {
            return true;
        }

        if (!$this->isExternalUrl($url)) {
            return false;
        }

        $host = $this->normalizeHost((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost') {
            return true;
        }

        $resolvedIps = $this->resolveHostIps($host);
        if ($resolvedIps === []) {
            return true;
        }

        foreach ($resolvedIps as $ip) {
            if (!$this->isPublicIp($ip)) {
                return true;
            }
        }

        return false;
    }

    private function detectMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    private function normalizeHost(string $host): string
    {
        return strtolower(trim($host, "[] \t\n\r\0\x0B"));
    }

    /**
     * @return array<string>
     */
    private function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];
        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $ips = array_merge($ips, $ipv4);
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Check if format and MIME type are consistent
     *
     * @param string $format File format
     * @param string $mimeType MIME type
     * @return bool True if consistent
     */
    private function isFormatMimeConsistent(string $format, string $mimeType): bool
    {
        $formatMimeMap = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
        ];

        if (!isset($formatMimeMap[$format])) {
            return false;
        }

        return in_array($mimeType, $formatMimeMap[$format], true);
    }

    /**
     * Parse size string to bytes
     *
     * @param string $size Size string (e.g., '10M', '1024K')
     * @return int Size in bytes
     */
    private function parseSize(string $size): int
    {
        $size = trim($size);
        $unit = strtoupper(substr($size, -1));
        $value = (int) substr($size, 0, -1);

        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => (int) $size
        };
    }

    /**
     * Format bytes to human readable string
     *
     * @param int $bytes Size in bytes
     * @return string Human readable size
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;

        if ($power >= count($units)) {
            $power = count($units) - 1;
        }

        return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
    }

    /**
     * Get default security configuration
     *
     * @return array Default config
     */
    /**
     * @return array<string, mixed>
     */
    private function getDefaultConfig(): array
    {
        return [
            'allowed_domains' => ['*'],
            'allowed_formats' => ['jpeg', 'jpg', 'png', 'gif', 'webp'],
            'allowed_mime_types' => [
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp'
            ],
            'validate_mime' => true,
            'max_width' => 2048,
            'max_height' => 2048,
            'max_filesize' => '10M',
            'disable_external_urls' => false,
        ];
    }
}
