<?php

declare(strict_types=1);

namespace Glueful\Encryption;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Encryption\Exceptions\DecryptionException;
use Glueful\Encryption\Exceptions\EncryptionException;
use Glueful\Encryption\Exceptions\InvalidKeyException;
use Glueful\Encryption\Exceptions\KeyNotFoundException;

class EncryptionService
{
    private const PREFIX = '$glueful$';
    private const VERSION = 'v1';
    private const NONCE_LENGTH = 12;
    private const TAG_LENGTH = 16;
    private const KEY_LENGTH = 32;
    private const KEY_ID_LENGTH = 8;

    private string $key;
    private string $keyId;
    /** @var array<string, string> */
    private array $keyMap = [];
    private ApplicationContext $context;

    public function __construct(ApplicationContext $context)
    {
        $this->context = $context;

        $this->key = $this->resolveAndValidateKey(
            config($context, 'encryption.key')
        );
        $this->keyId = $this->deriveKeyId($this->key);
        $this->keyMap[$this->keyId] = $this->key;

        $previous = config($context, 'encryption.previous_keys', []);
        if (is_array($previous)) {
            foreach ($previous as $prevKey) {
                if (!is_string($prevKey) || $prevKey === '') {
                    continue;
                }
                $resolved = $this->resolveAndValidateKey($prevKey);
                $prevId = $this->deriveKeyId($resolved);
                if (!isset($this->keyMap[$prevId])) {
                    $this->keyMap[$prevId] = $resolved;
                }
            }
        }
    }

    public function encrypt(string $value, ?string $aad = null): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new EncryptionException('Input must be valid UTF-8. Use encryptBinary() for raw bytes.');
        }

        return $this->encryptRaw($value, $aad, $this->key, $this->keyId);
    }

    public function decrypt(string $encrypted, ?string $aad = null): string
    {
        $plaintext = $this->decryptRaw($encrypted, $aad);

        if (!mb_check_encoding($plaintext, 'UTF-8')) {
            throw new DecryptionException('Decrypted value is not valid UTF-8.');
        }

        return $plaintext;
    }

    public function encryptBinary(string $bytes, ?string $aad = null): string
    {
        return $this->encryptRaw($bytes, $aad, $this->key, $this->keyId);
    }

    public function decryptBinary(string $encrypted, ?string $aad = null): string
    {
        return $this->decryptRaw($encrypted, $aad);
    }

    public function isEncrypted(string $value): bool
    {
        return $this->parseEncrypted($value) !== null;
    }

    public function rotateKey(string $newKey): void
    {
        $resolved = $this->resolveAndValidateKey($newKey);
        $this->key = $resolved;
        $this->keyId = $this->deriveKeyId($resolved);
        $this->keyMap[$this->keyId] = $resolved;
    }

    public function encryptWithKey(string $value, string $key, ?string $aad = null): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new EncryptionException('Input must be valid UTF-8. Use encryptBinaryWithKey() for raw bytes.');
        }

        $resolved = $this->resolveAndValidateKey($key);
        $keyId = $this->deriveKeyId($resolved);
        return $this->encryptRaw($value, $aad, $resolved, $keyId);
    }

    public function decryptWithKey(string $encrypted, string $key, ?string $aad = null): string
    {
        $plaintext = $this->decryptRaw($encrypted, $aad, $key);
        if (!mb_check_encoding($plaintext, 'UTF-8')) {
            throw new DecryptionException('Decrypted value is not valid UTF-8.');
        }
        return $plaintext;
    }

    public function encryptBinaryWithKey(string $bytes, string $key, ?string $aad = null): string
    {
        $resolved = $this->resolveAndValidateKey($key);
        $keyId = $this->deriveKeyId($resolved);
        return $this->encryptRaw($bytes, $aad, $resolved, $keyId);
    }

    public function decryptBinaryWithKey(string $encrypted, string $key, ?string $aad = null): string
    {
        return $this->decryptRaw($encrypted, $aad, $key);
    }

    public function encryptFile(string $sourcePath, string $destPath): void
    {
        $data = @file_get_contents($sourcePath);
        if ($data === false) {
            throw new EncryptionException('Failed to read source file.');
        }

        $encrypted = $this->encryptBinary($data);
        if (@file_put_contents($destPath, $encrypted) === false) {
            throw new EncryptionException('Failed to write encrypted file.');
        }
    }

    public function decryptFile(string $sourcePath, string $destPath): void
    {
        $data = @file_get_contents($sourcePath);
        if ($data === false) {
            throw new DecryptionException('Failed to read encrypted file.');
        }

        $decrypted = $this->decryptBinary($data);
        if (@file_put_contents($destPath, $decrypted) === false) {
            throw new DecryptionException('Failed to write decrypted file.');
        }
    }

    /**
     * @param resource $inputStream
     */
    public function encryptStream($inputStream): string
    {
        $data = stream_get_contents($inputStream);
        if ($data === false) {
            throw new EncryptionException('Failed to read stream.');
        }

        return $this->encryptBinary($data);
    }

    private function encryptRaw(string $value, ?string $aad, string $key, string $keyId): string
    {
        $nonce = random_bytes(self::NONCE_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $value,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad ?? '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new EncryptionException('Encryption failed.');
        }

        return $this->formatOutput($keyId, $nonce, $ciphertext, $tag);
    }

    private function decryptRaw(string $encrypted, ?string $aad, ?string $overrideKey = null): string
    {
        $parsed = $this->parseEncrypted($encrypted);
        if ($parsed === null) {
            throw new DecryptionException('Invalid encrypted payload.');
        }

        $key = $overrideKey !== null
            ? $this->resolveAndValidateKey($overrideKey)
            : ($this->keyMap[$parsed['key_id']] ?? null);
        if ($key === null) {
            throw new KeyNotFoundException(
                'Unknown key ID - encryption key may have been rotated out.'
            );
        }

        $plaintext = openssl_decrypt(
            $parsed['ciphertext'],
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $parsed['nonce'],
            $parsed['tag'],
            $aad ?? ''
        );

        if ($plaintext === false) {
            throw new DecryptionException('Decryption failed.');
        }

        return $plaintext;
    }

    /**
     * @return array{key_id: string, nonce: string, ciphertext: string, tag: string}|null
     */
    private function parseEncrypted(string $value): ?array
    {
        if (!str_starts_with($value, self::PREFIX . self::VERSION . '$')) {
            return null;
        }

        $parts = explode('$', $value);
        if (count($parts) < 7) {
            return null;
        }

        $prefix = $parts[1] ?? '';
        $version = $parts[2] ?? '';

        if ('$' . $prefix . '$' !== self::PREFIX || $version !== self::VERSION) {
            return null;
        }

        $keyId = $parts[3] ?? '';
        $nonce = $parts[4] ?? '';
        $ciphertext = $parts[5] ?? '';
        $tag = $parts[6] ?? '';

        if ($keyId === '' || $nonce === '' || $tag === '' || $ciphertext === '') {
            return null;
        }

        $decodedNonce = $this->base64UrlDecode($nonce);
        $decodedCipher = $this->base64UrlDecode($ciphertext);
        $decodedTag = $this->base64UrlDecode($tag);

        if ($decodedNonce === null || $decodedCipher === null || $decodedTag === null) {
            return null;
        }

        if (strlen($decodedNonce) !== self::NONCE_LENGTH || strlen($decodedTag) !== self::TAG_LENGTH) {
            return null;
        }

        return [
            'key_id' => $keyId,
            'nonce' => $decodedNonce,
            'ciphertext' => $decodedCipher,
            'tag' => $decodedTag,
        ];
    }

    private function formatOutput(string $keyId, string $nonce, string $ciphertext, string $tag): string
    {
        return self::PREFIX . self::VERSION . '$'
            . $keyId . '$'
            . $this->base64UrlEncode($nonce) . '$'
            . $this->base64UrlEncode($ciphertext) . '$'
            . $this->base64UrlEncode($tag);
    }

    private function resolveAndValidateKey(mixed $key): string
    {
        if (!is_string($key) || $key === '') {
            throw new KeyNotFoundException(
                'Encryption key not configured. Run: php glueful generate:key'
            );
        }

        // Handle base64: prefix (recommended format for APP_KEY)
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded === false) {
                throw new InvalidKeyException('Invalid base64 encoding in encryption key.');
            }
            $key = $decoded;
        }

        if (strlen($key) !== self::KEY_LENGTH) {
            throw new InvalidKeyException(sprintf(
                'Encryption key must be exactly %d bytes, got %d bytes.',
                self::KEY_LENGTH,
                strlen($key)
            ));
        }

        return $key;
    }

    private function deriveKeyId(string $key): string
    {
        return substr(hash('sha256', $key), 0, self::KEY_ID_LENGTH);
    }

    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $value = strtr($value, '-_', '+/');
        $pad = strlen($value) % 4;
        if ($pad > 0) {
            $value .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode($value, true);
        return $decoded === false ? null : $decoded;
    }
}
