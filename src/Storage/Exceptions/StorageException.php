<?php

declare(strict_types=1);

namespace Glueful\Storage\Exceptions;

use League\Flysystem\FilesystemException;
use Glueful\Storage\Support\ExceptionClassifier;

class StorageException extends \RuntimeException
{
    public static function fromFlysystem(FilesystemException $e, string $path = ''): self
    {
        $info = ExceptionClassifier::classify($e);
        $prefix = sprintf('[reason=%s http=%d]', $info['reason'], $info['http_status']);
        $base = $e->getMessage();
        $message = $prefix . ' ' . ($path !== '' ? $base . " (path: {$path})" : $base);

        return new self($message, 0, $e);
    }

    /**
     * Convenience: return the classified reason code, if present.
     */
    public function reason(): ?string
    {
        $parsed = \Glueful\Storage\Support\ExceptionClassifier::parseFromMessage($this->getMessage());
        /** @var string|null $reason */
        $reason = $parsed['reason'] ?? null;
        return $reason;
    }

    /**
     * Convenience: return the suggested HTTP status, if present.
     */
    public function httpStatus(): ?int
    {
        $parsed = \Glueful\Storage\Support\ExceptionClassifier::parseFromMessage($this->getMessage());
        /** @var int|null $status */
        $status = $parsed['http_status'] ?? null;
        return $status;
    }
}
