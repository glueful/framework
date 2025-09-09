<?php

declare(strict_types=1);

namespace Glueful\Support\FieldSelection\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class InvalidFieldSelectionException extends RuntimeException
{
    /** @param array<string,mixed> $meta */
    private function __construct(string $message, private array $meta = [])
    {
        parent::__construct($message);
    }

    public static function depthExceeded(int $max): self
    {
        return new self("Maximum field selection depth exceeded (max={$max}).", [
            'reason' => 'DEPTH_EXCEEDED',
            'max' => $max,
        ]);
    }

    public static function tooManyFields(int $max, int $got): self
    {
        return new self("Too many selected fields: {$got} (max={$max}).", [
            'reason' => 'FIELDS_LIMIT',
            'max' => $max,
            'got' => $got,
        ]);
    }

    /**
     * @param string[] $unknown
     * @param string[] $allowed
     */
    public static function unknownFields(array $unknown, array $allowed): self
    {
        return new self('Unknown fields in strict mode.', [
            'reason' => 'INVALID_FIELDS',
            'unknown' => array_values($unknown),
            'allowed' => array_values($allowed),
        ]);
    }

    public function toResponse(): Response
    {
        return new JsonResponse([
            'error' => [
                'code' => $this->meta['reason'] ?? 'FIELD_SELECTION_ERROR',
                'message' => $this->getMessage(),
                'meta' => $this->meta,
            ],
        ], 400);
    }
}
