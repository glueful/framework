<?php

declare(strict_types=1);

namespace Glueful\Encryption\Exceptions;

/**
 * Exception thrown when an encryption key is invalid.
 *
 * This includes:
 * - Key is empty or null
 * - Key is the wrong length (must be 32 bytes for AES-256)
 * - Key has invalid base64 encoding
 */
class InvalidKeyException extends EncryptionException
{
}
