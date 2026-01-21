<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Casts;

use Glueful\Database\ORM\Contracts\CastsAttributes;
use Glueful\Database\ORM\Model;
use RuntimeException;

/**
 * Encrypted String Cast
 *
 * Encrypts a string attribute before storing in the database and
 * decrypts it when retrieving. Uses AES-256-CBC encryption.
 *
 * Requires an encryption key to be set via the ENCRYPTION_KEY environment
 * variable or the setKey() method.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class AsEncryptedString implements CastsAttributes
{
    /**
     * The encryption key
     */
    private static ?string $key = null;

    /**
     * The cipher algorithm
     */
    private const CIPHER = 'aes-256-cbc';

    /**
     * Set the encryption key
     *
     * @param string $key
     * @return void
     */
    public static function setKey(string $key): void
    {
        self::$key = $key;
    }

    /**
     * Get the encryption key
     *
     * @return string
     * @throws RuntimeException If no key is set
     */
    protected function getKey(): string
    {
        $key = self::$key ?? ($_ENV['ENCRYPTION_KEY'] ?? null);

        if ($key === null) {
            throw new RuntimeException(
                'No encryption key has been set. Set the ENCRYPTION_KEY environment variable ' .
                'or call AsEncryptedString::setKey().'
            );
        }

        return $key;
    }

    /**
     * Decrypt the given value
     *
     * @param Model $model
     * @param string $key
     * @param mixed $value
     * @param array<string, mixed> $attributes
     * @return string|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $data = base64_decode($value, true);

        if ($data === false) {
            return null;
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);

        if ($ivLength === false || strlen($data) < $ivLength) {
            return null;
        }

        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);

        $decrypted = openssl_decrypt(
            $encrypted,
            self::CIPHER,
            $this->getKey(),
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decrypted === false) {
            return null;
        }

        return $decrypted;
    }

    /**
     * Encrypt the given value for storage
     *
     * @param Model $model
     * @param string $key
     * @param mixed $value
     * @param array<string, mixed> $attributes
     * @return string|null
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);

        if ($ivLength === false) {
            throw new RuntimeException('Failed to get cipher IV length.');
        }

        $iv = random_bytes($ivLength);

        $encrypted = openssl_encrypt(
            (string) $value,
            self::CIPHER,
            $this->getKey(),
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $encrypted);
    }
}
