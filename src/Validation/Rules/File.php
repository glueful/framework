<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * File rule - validates uploaded file
 *
 * @example
 * new File()                      // Any file
 * new File(['pdf', 'doc'])        // Only PDF and DOC files
 * new File(['pdf'], 2048)         // PDF files max 2MB
 */
final class File implements Rule
{
    /**
     * @param array<string>|null $allowedExtensions Allowed file extensions
     * @param int|null $maxSize Maximum file size in kilobytes
     * @param int|null $minSize Minimum file size in kilobytes
     */
    public function __construct(
        private ?array $allowedExtensions = null,
        private ?int $maxSize = null,
        private ?int $minSize = null
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
            return "The {$field} must be a file.";
        }

        // Check if upload was successful
        if (!$value->isValid()) {
            return "The {$field} failed to upload.";
        }

        // Check extension
        if ($this->allowedExtensions !== null) {
            $extension = strtolower($value->getClientOriginalExtension());
            $allowedLower = array_map('strtolower', $this->allowedExtensions);

            if (!in_array($extension, $allowedLower, true)) {
                $allowed = implode(', ', $this->allowedExtensions);
                return "The {$field} must be a file of type: {$allowed}.";
            }
        }

        // Check max size (in kilobytes)
        if ($this->maxSize !== null) {
            $sizeInKb = $value->getSize() / 1024;
            if ($sizeInKb > $this->maxSize) {
                return "The {$field} must not be greater than {$this->maxSize} kilobytes.";
            }
        }

        // Check min size (in kilobytes)
        if ($this->minSize !== null) {
            $sizeInKb = $value->getSize() / 1024;
            if ($sizeInKb < $this->minSize) {
                return "The {$field} must be at least {$this->minSize} kilobytes.";
            }
        }

        return null;
    }
}
