<?php

declare(strict_types=1);

namespace Glueful\Storage;

class PathGuard
{
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config
     */
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
     * @throws \InvalidArgumentException
     */
    public function validate(string $path): string
    {
        // Check for null bytes (security)
        if (strpos($path, "\0") !== false) {
            throw new \InvalidArgumentException("Path contains null byte");
        }

        // Reject path traversal
        if (str_contains($path, '..')) {
            throw new \InvalidArgumentException("Path traversal detected");
        }

        // Reject absolute paths
        if (!(bool)($this->config['allow_absolute'] ?? false) && $this->isAbsolute($path)) {
            throw new \InvalidArgumentException("Absolute paths not allowed");
        }

        // Normalize path
        $path = $this->normalize($path);

        // Check length
        if (strlen($path) > $this->config['max_path_length']) {
            throw new \InvalidArgumentException("Path exceeds maximum length");
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
