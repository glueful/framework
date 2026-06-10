<?php

declare(strict_types=1);

namespace Glueful\Storage\Exceptions;

final class UnsupportedStorageDriverException extends \InvalidArgumentException
{
    /** @var array<string, string> */
    private const PACKAGE_SUGGESTIONS = [
        's3' => 'glueful/storage-s3',
        'gcs' => 'glueful/storage-gcs',
        'azure' => 'glueful/storage-azure',
    ];

    public static function forDriver(string $driver): self
    {
        $driver = trim($driver);
        $display = $driver !== '' ? $driver : '(empty)';
        $message = "Unsupported disk driver '{$display}'.";

        if (isset(self::PACKAGE_SUGGESTIONS[$driver])) {
            $message .= ' Install it with: composer require ' . self::PACKAGE_SUGGESTIONS[$driver] . '.';
        } else {
            $message .= ' Register a StorageDriverFactoryInterface tagged storage.driver_factory.';
        }

        return new self($message);
    }
}
