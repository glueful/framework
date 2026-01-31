<?php

declare(strict_types=1);

namespace Glueful\Encryption\Exceptions;

/**
 * Exception thrown when no encryption key is configured.
 *
 * This typically means APP_KEY is not set in the environment.
 * Generate a key with: php glueful generate:key
 */
class KeyNotFoundException extends EncryptionException
{
}
