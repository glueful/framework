<?php

declare(strict_types=1);

namespace Glueful\Storage\Support;

use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;

/**
 * Minimal classifier for Flysystem exceptions.
 *
 * Produces a simple reason code and HTTP status mapping
 * without introducing a custom exception hierarchy.
 */
final class ExceptionClassifier
{
    /**
     * Classify a Flysystem exception into a reason and HTTP status code.
     *
     * @return array{reason:string,http_status:int,class:string,message:string}
     */
    public static function classify(FilesystemException $e): array
    {
        $reason = self::reason($e);
        $status = self::httpStatusFor($reason);

        return [
            'reason' => $reason,
            'http_status' => $status,
            'class' => $e::class,
            'message' => $e->getMessage(),
        ];
    }

    /**
     * Return a concise, stable reason code for the error.
     */
    public static function reason(FilesystemException $e): string
    {
        // File operations
        if ($e instanceof UnableToReadFile) {
            return 'io_read_failed';
        }
        if ($e instanceof UnableToWriteFile) {
            return 'io_write_failed';
        }
        if ($e instanceof UnableToDeleteFile) {
            return 'io_delete_failed';
        }
        if ($e instanceof UnableToMoveFile) {
            return 'io_move_failed';
        }
        if ($e instanceof UnableToCopyFile) {
            return 'io_copy_failed';
        }

        // Directory operations
        if ($e instanceof UnableToCreateDirectory) {
            return 'dir_create_failed';
        }
        if ($e instanceof UnableToDeleteDirectory) {
            return 'dir_delete_failed';
        }

        // Existence + conflicts
        if ($e instanceof UnableToCheckExistence) {
            return 'existence_check_failed';
        }

        // Metadata/visibility
        if ($e instanceof UnableToRetrieveMetadata) {
            return 'metadata_retrieve_failed';
        }
        if ($e instanceof UnableToSetVisibility) {
            return 'visibility_set_failed';
        }

        // Listing
        if ($e instanceof UnableToListContents) {
            return 'list_failed';
        }

        // Fallback
        return 'unknown_error';
    }

    /**
     * Map reason codes to HTTP status codes for APIs.
     */
    public static function httpStatusFor(string $reason): int
    {
        return match ($reason) {
            'io_read_failed' => 404,
            'visibility_set_failed' => 403,
            default => 500,
        };
    }

    /**
     * Parse a classified message prefix like:
     * "[reason=io_read_failed http=404] ..."
     *
     * @return array{reason: string|null, http_status: int|null}
     */
    public static function parseFromMessage(string $message): array
    {
        $reason = null;
        $http = null;

        if (preg_match('/^\[reason=([a-z0-9_\-]+)\s+http=(\d{3})\]\s*/i', $message, $m) === 1) {
            $reason = strtolower($m[1]);
            $http = (int) $m[2];
        }

        return [
            'reason' => $reason,
            'http_status' => $http,
        ];
    }
}
