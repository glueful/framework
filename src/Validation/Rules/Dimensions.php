<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Dimensions rule - validates image dimensions
 *
 * @example
 * new Dimensions(['min_width' => 100, 'min_height' => 100])
 * new Dimensions(['max_width' => 1000, 'max_height' => 1000])
 * new Dimensions(['width' => 300, 'height' => 300])  // Exact dimensions
 * new Dimensions(['ratio' => '16/9'])  // Aspect ratio
 */
final class Dimensions implements Rule
{
    /**
     * @param array<string, int|string> $constraints Dimension constraints
     */
    public function __construct(
        private array $constraints = []
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

        // Get file path
        $path = null;
        if ($value instanceof UploadedFile) {
            if (!$value->isValid()) {
                return "The {$field} failed to upload.";
            }
            $path = $value->getPathname();
        } elseif (is_string($value) && file_exists($value)) {
            $path = $value;
        }

        if ($path === null) {
            return "The {$field} must be an image file.";
        }

        // Get image dimensions
        $imageInfo = @getimagesize($path);
        if ($imageInfo === false) {
            return "The {$field} must be an image file.";
        }

        [$width, $height] = $imageInfo;

        // Check exact width
        if (isset($this->constraints['width'])) {
            $expected = (int) $this->constraints['width'];
            if ($width !== $expected) {
                return "The {$field} must be {$expected} pixels wide.";
            }
        }

        // Check exact height
        if (isset($this->constraints['height'])) {
            $expected = (int) $this->constraints['height'];
            if ($height !== $expected) {
                return "The {$field} must be {$expected} pixels tall.";
            }
        }

        // Check min width
        if (isset($this->constraints['min_width'])) {
            $min = (int) $this->constraints['min_width'];
            if ($width < $min) {
                return "The {$field} must be at least {$min} pixels wide.";
            }
        }

        // Check max width
        if (isset($this->constraints['max_width'])) {
            $max = (int) $this->constraints['max_width'];
            if ($width > $max) {
                return "The {$field} must not be greater than {$max} pixels wide.";
            }
        }

        // Check min height
        if (isset($this->constraints['min_height'])) {
            $min = (int) $this->constraints['min_height'];
            if ($height < $min) {
                return "The {$field} must be at least {$min} pixels tall.";
            }
        }

        // Check max height
        if (isset($this->constraints['max_height'])) {
            $max = (int) $this->constraints['max_height'];
            if ($height > $max) {
                return "The {$field} must not be greater than {$max} pixels tall.";
            }
        }

        // Check aspect ratio
        if (isset($this->constraints['ratio'])) {
            $ratio = $this->constraints['ratio'];
            if (!$this->validateRatio($width, $height, (string) $ratio)) {
                return "The {$field} must have an aspect ratio of {$ratio}.";
            }
        }

        return null;
    }

    /**
     * Validate aspect ratio
     */
    private function validateRatio(int $width, int $height, string $ratio): bool
    {
        // Parse ratio string (e.g., "16/9" or "1.777")
        if (str_contains($ratio, '/')) {
            [$ratioWidth, $ratioHeight] = explode('/', $ratio);
            $expectedRatio = (float) $ratioWidth / (float) $ratioHeight;
        } else {
            $expectedRatio = (float) $ratio;
        }

        $actualRatio = $width / $height;

        // Allow small tolerance for floating point comparison
        return abs($actualRatio - $expectedRatio) < 0.01;
    }
}
