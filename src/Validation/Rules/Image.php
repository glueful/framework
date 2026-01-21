<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Image rule - validates uploaded image file
 *
 * @example
 * new Image()                           // Any image
 * new Image(['jpeg', 'png'])            // Only JPEG and PNG
 * new Image(['jpeg', 'png'], 2048)      // Max 2MB
 */
final class Image implements Rule
{
    /**
     * Valid image MIME types
     */
    private const IMAGE_MIMES = [
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
    ];

    /**
     * @param array<string>|null $allowedTypes Allowed image types (jpeg, png, gif, etc.)
     * @param int|null $maxSize Maximum file size in kilobytes
     */
    public function __construct(
        private ?array $allowedTypes = null,
        private ?int $maxSize = null
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function validate(mixed $value, array $context = []): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $field = $context['field'] ?? 'field';

        // Check if it's an uploaded file
        if (!$value instanceof UploadedFile) {
            return "The {$field} must be an image.";
        }

        // Check if upload was successful
        if (!$value->isValid()) {
            return "The {$field} failed to upload.";
        }

        // Check if it's an image
        $mimeType = $value->getMimeType();
        if ($mimeType === null || !str_starts_with($mimeType, 'image/')) {
            return "The {$field} must be an image.";
        }

        // Check allowed types
        if ($this->allowedTypes !== null) {
            $allowedMimes = [];
            foreach ($this->allowedTypes as $type) {
                $type = strtolower($type);
                if (isset(self::IMAGE_MIMES[$type])) {
                    $allowedMimes[] = self::IMAGE_MIMES[$type];
                }
            }

            if (!in_array($mimeType, $allowedMimes, true)) {
                $allowed = implode(', ', $this->allowedTypes);
                return "The {$field} must be an image of type: {$allowed}.";
            }
        }

        // Check max size (in kilobytes)
        if ($this->maxSize !== null) {
            $sizeInKb = $value->getSize() / 1024;
            if ($sizeInKb > $this->maxSize) {
                return "The {$field} must not be greater than {$this->maxSize} kilobytes.";
            }
        }

        return null;
    }
}
