<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Casts;

use Glueful\Database\ORM\Contracts\CastsAttributes;
use Glueful\Database\ORM\Model;
use Glueful\Encryption\EncryptionService;
use RuntimeException;

/**
 * Encrypted String Cast
 *
 * Encrypts a string attribute before storing it and decrypts it when reading, through the
 * framework EncryptionService (AES-256-GCM keyed by APP_KEY, with APP_PREVIOUS_KEYS rotation).
 * The ciphertext is bound to "<table>.<column>" as additional authenticated data — the same
 * AAD `php glueful encryption:rotate --table=... --columns=...` uses — so values cannot be
 * swapped between columns and the rotate command can re-key them.
 *
 * Legacy values written by earlier releases (AES-256-CBC keyed by ENCRYPTION_KEY or
 * setKey()) are still decrypted on read; they are re-encrypted with EncryptionService the
 * next time the attribute is assigned and saved.
 *
 * The EncryptionService is resolved from the model's ApplicationContext.
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
     * The legacy cipher algorithm (read-only fallback)
     */
    private const CIPHER = 'aes-256-cbc';

    /**
     * Set the legacy (AES-256-CBC) decryption key
     *
     * @param string $key
     * @return void
     */
    public static function setKey(string $key): void
    {
        self::$key = $key;
    }

    /**
     * Get the legacy (AES-256-CBC) decryption key
     *
     * @return string
     * @throws RuntimeException If no key is set
     */
    protected function getKey(): string
    {
        $fromEnvironment = env('ENCRYPTION_KEY');
        $key = self::$key ?? (is_string($fromEnvironment) && $fromEnvironment !== '' ? $fromEnvironment : null);

        if ($key === null) {
            throw new RuntimeException(
                'No encryption key has been set. Set the ENCRYPTION_KEY environment variable ' .
                'or call AsEncryptedString::setKey().'
            );
        }

        return $key;
    }

    /**
     * Resolve the framework EncryptionService from the model's context
     *
     * @throws RuntimeException When the model has no ApplicationContext to resolve it from
     */
    protected function encrypter(Model $model): EncryptionService
    {
        $context = $model->getContext();

        if ($context === null || !$context->hasContainer()) {
            throw new RuntimeException(
                'AsEncryptedString needs the model\'s ApplicationContext to resolve ' .
                EncryptionService::class . '. Construct the model with a context.'
            );
        }

        $service = app($context, EncryptionService::class);

        if (!$service instanceof EncryptionService) {
            throw new RuntimeException('The container did not return an ' . EncryptionService::class . '.');
        }

        return $service;
    }

    /**
     * The additional authenticated data binding a ciphertext to its column
     */
    protected function aad(Model $model, string $key): string
    {
        return $model->getTable() . '.' . $key;
    }

    /**
     * Decrypt the given value
     *
     * EncryptionService ciphertexts are decrypted with it; anything else is treated as a
     * legacy AES-256-CBC value.
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

        $value = (string) $value;

        if (str_starts_with($value, '$glueful$')) {
            $encrypter = $this->encrypter($model);

            if ($encrypter->isEncrypted($value)) {
                return $encrypter->decrypt($value, $this->aad($model, $key));
            }
        }

        return $this->legacyDecrypt($value);
    }

    /**
     * Decrypt a value written by the legacy AES-256-CBC implementation
     *
     * @param string $value
     * @return string|null
     */
    protected function legacyDecrypt(string $value): ?string
    {
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
     * Encrypt the given value for storage with the framework EncryptionService
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

        return $this->encrypter($model)->encrypt((string) $value, $this->aad($model, $key));
    }
}
