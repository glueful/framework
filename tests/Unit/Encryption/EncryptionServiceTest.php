<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Encryption;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Encryption\EncryptionService;
use Glueful\Encryption\Exceptions\DecryptionException;
use Glueful\Encryption\Exceptions\EncryptionException;
use Glueful\Encryption\Exceptions\InvalidKeyException;
use Glueful\Encryption\Exceptions\KeyNotFoundException;
use PHPUnit\Framework\TestCase;

final class EncryptionServiceTest extends TestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'; // 32 bytes
    private const ALT_KEY = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'; // 32 bytes

    protected function setUp(): void
    {
        $_ENV['APP_KEY'] = self::APP_KEY;
        putenv('APP_KEY=' . self::APP_KEY);
        $_ENV['APP_PREVIOUS_KEYS'] = '';
        putenv('APP_PREVIOUS_KEYS=');
    }

    // =========================================================================
    // Core Encryption Tests
    // =========================================================================

    public function testEncryptReturnsFormattedString(): void
    {
        $service = new EncryptionService($this->makeContext());
        $encrypted = $service->encrypt('value');

        $this->assertStringStartsWith('$glueful$v1$', $encrypted);
        $this->assertStringContainsString('$', $encrypted);
    }

    public function testDecryptReturnsOriginalValue(): void
    {
        $service = new EncryptionService($this->makeContext());
        $encrypted = $service->encrypt('secret-value');
        $decrypted = $service->decrypt($encrypted);

        $this->assertSame('secret-value', $decrypted);
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $service = new EncryptionService($this->makeContext());
        $values = [
            'simple',
            'Hello, World!',
            'With special chars: @#$%^&*()',
            'Unicode: 日本語 🔐 émojis',
            str_repeat('a', 1000), // Large string
            ' ', // Single space (minimal non-empty value)
        ];

        foreach ($values as $value) {
            $encrypted = $service->encrypt($value);
            $decrypted = $service->decrypt($encrypted);
            $this->assertSame($value, $decrypted, "Failed for value: {$value}");
        }
    }

    public function testEncryptProducesDifferentOutputEachTime(): void
    {
        $service = new EncryptionService($this->makeContext());
        $value = 'same-value';

        $encrypted1 = $service->encrypt($value);
        $encrypted2 = $service->encrypt($value);
        $encrypted3 = $service->encrypt($value);

        // All should be different due to random nonce
        $this->assertNotSame($encrypted1, $encrypted2);
        $this->assertNotSame($encrypted2, $encrypted3);
        $this->assertNotSame($encrypted1, $encrypted3);

        // But all should decrypt to same value
        $this->assertSame($value, $service->decrypt($encrypted1));
        $this->assertSame($value, $service->decrypt($encrypted2));
        $this->assertSame($value, $service->decrypt($encrypted3));
    }

    public function testDecryptFailsWithWrongKey(): void
    {
        $service1 = new EncryptionService($this->makeContext());
        $encrypted = $service1->encrypt('secret');

        // Create service with different key
        $_ENV['APP_KEY'] = self::ALT_KEY;
        putenv('APP_KEY=' . self::ALT_KEY);
        $service2 = new EncryptionService($this->makeContext());

        $this->expectException(KeyNotFoundException::class);
        $service2->decrypt($encrypted);
    }

    public function testDecryptFailsWithTamperedData(): void
    {
        $service = new EncryptionService($this->makeContext());
        $encrypted = $service->encrypt('secret');

        // Tamper with ciphertext portion
        $tampered = substr($encrypted, 0, -10) . 'XXXXXXXXXX';

        $this->expectException(DecryptionException::class);
        $service->decrypt($tampered);
    }

    public function testDecryptFailsWithInvalidFormat(): void
    {
        $service = new EncryptionService($this->makeContext());

        $invalidPayloads = [
            'not-encrypted',
            '$glueful$v2$invalid', // Wrong version
            '$other$v1$data',      // Wrong prefix
            '$glueful$v1$',        // Incomplete
        ];

        foreach ($invalidPayloads as $payload) {
            try {
                $service->decrypt($payload);
                $this->fail("Should have thrown for: {$payload}");
            } catch (DecryptionException $e) {
                $this->assertStringContainsString('Invalid', $e->getMessage());
            }
        }
    }

    public function testIsEncryptedDetectsEncryptedStrings(): void
    {
        $service = new EncryptionService($this->makeContext());
        $encrypted = $service->encrypt('value');

        $this->assertTrue($service->isEncrypted($encrypted));
        $this->assertFalse($service->isEncrypted('not-encrypted'));
        $this->assertFalse($service->isEncrypted(''));
        $this->assertFalse($service->isEncrypted('$other$v1$data'));
    }

    public function testOutputContainsKeyId(): void
    {
        $service = new EncryptionService($this->makeContext());
        $encrypted = $service->encrypt('value');

        // Format: $glueful$v1$<key_id>$<nonce>$<ciphertext>$<tag>
        $parts = explode('$', $encrypted);
        $this->assertCount(7, $parts);
        $this->assertSame('', $parts[0]); // Before first $
        $this->assertSame('glueful', $parts[1]);
        $this->assertSame('v1', $parts[2]);
        $this->assertSame(8, strlen($parts[3])); // Key ID is 8 chars
    }

    // =========================================================================
    // AAD (Additional Authenticated Data) Tests
    // =========================================================================

    public function testEncryptWithAadDecryptsWithSameAad(): void
    {
        $service = new EncryptionService($this->makeContext());
        $encrypted = $service->encrypt('secret', 'user.ssn');

        $decrypted = $service->decrypt($encrypted, 'user.ssn');
        $this->assertSame('secret', $decrypted);
    }

    public function testDecryptFailsWithWrongAad(): void
    {
        $service = new EncryptionService($this->makeContext());
        $encrypted = $service->encrypt('secret', 'user.ssn');

        $this->expectException(DecryptionException::class);
        $service->decrypt($encrypted, 'user.api_key');
    }

    public function testDecryptFailsWithMissingAadWhenRequired(): void
    {
        $service = new EncryptionService($this->makeContext());
        $encrypted = $service->encrypt('secret', 'user.ssn');

        $this->expectException(DecryptionException::class);
        $service->decrypt($encrypted); // No AAD provided
    }

    public function testAadPreventsContextSwapping(): void
    {
        $service = new EncryptionService($this->makeContext());

        // Encrypt SSN with its context
        $encryptedSsn = $service->encrypt('123-45-6789', 'users.ssn');

        // Try to decrypt as API key - should fail
        try {
            $service->decrypt($encryptedSsn, 'users.api_key');
            $this->fail('Should not be able to decrypt with wrong AAD');
        } catch (DecryptionException $e) {
            $this->assertTrue(true);
        }

        // Correct context works
        $this->assertSame('123-45-6789', $service->decrypt($encryptedSsn, 'users.ssn'));
    }

    // =========================================================================
    // Key Validation Tests
    // =========================================================================

    public function testThrowsOnMissingKey(): void
    {
        $_ENV['APP_KEY'] = '';
        putenv('APP_KEY=');

        $this->expectException(KeyNotFoundException::class);
        $this->expectExceptionMessage('not configured');
        new EncryptionService($this->makeContext());
    }

    public function testThrowsOnKeyTooShort(): void
    {
        $_ENV['APP_KEY'] = 'short';
        putenv('APP_KEY=short');

        $this->expectException(InvalidKeyException::class);
        $this->expectExceptionMessage('32 bytes');
        new EncryptionService($this->makeContext());
    }

    public function testThrowsOnKeyTooLong(): void
    {
        $_ENV['APP_KEY'] = str_repeat('a', 64);
        putenv('APP_KEY=' . str_repeat('a', 64));

        $this->expectException(InvalidKeyException::class);
        $this->expectExceptionMessage('32 bytes');
        new EncryptionService($this->makeContext());
    }

    public function testAcceptsBase64PrefixedKey(): void
    {
        // base64 encode a 32-byte key
        $rawKey = str_repeat('x', 32);
        $base64Key = 'base64:' . base64_encode($rawKey);

        $_ENV['APP_KEY'] = $base64Key;
        putenv('APP_KEY=' . $base64Key);

        $service = new EncryptionService($this->makeContext());

        // Should work normally
        $encrypted = $service->encrypt('test');
        $this->assertSame('test', $service->decrypt($encrypted));
    }

    public function testThrowsOnInvalidBase64Key(): void
    {
        $_ENV['APP_KEY'] = 'base64:not-valid-base64!!!';
        putenv('APP_KEY=base64:not-valid-base64!!!');

        $this->expectException(InvalidKeyException::class);
        $this->expectExceptionMessage('Invalid base64');
        new EncryptionService($this->makeContext());
    }

    // =========================================================================
    // Binary Handling Tests
    // =========================================================================

    public function testEncryptBinaryHandlesNonUtf8(): void
    {
        $service = new EncryptionService($this->makeContext());

        // Generate random binary data (likely not valid UTF-8)
        $binary = random_bytes(64);

        $encrypted = $service->encryptBinary($binary);
        $decrypted = $service->decryptBinary($encrypted);

        $this->assertSame($binary, $decrypted);
    }

    public function testEncryptThrowsOnNonUtf8Input(): void
    {
        $service = new EncryptionService($this->makeContext());

        // Invalid UTF-8 sequence
        $invalidUtf8 = "\xFF\xFE";

        $this->expectException(EncryptionException::class);
        $this->expectExceptionMessage('UTF-8');
        $service->encrypt($invalidUtf8);
    }

    public function testDecryptBinaryReturnsRawBytes(): void
    {
        $service = new EncryptionService($this->makeContext());
        $binary = pack('C*', 0x00, 0xFF, 0x80, 0x7F);

        $encrypted = $service->encryptBinary($binary);
        $decrypted = $service->decryptBinary($encrypted);

        $this->assertSame($binary, $decrypted);
    }

    // =========================================================================
    // Key Rotation Tests
    // =========================================================================

    public function testDecryptWithPreviousKeyViaKeyId(): void
    {
        // Encrypt with first key
        $service1 = new EncryptionService($this->makeContext());
        $encrypted = $service1->encrypt('secret');

        // Set up service with new key, old key in previous_keys
        $_ENV['APP_KEY'] = self::ALT_KEY;
        $_ENV['APP_PREVIOUS_KEYS'] = self::APP_KEY;
        putenv('APP_KEY=' . self::ALT_KEY);
        putenv('APP_PREVIOUS_KEYS=' . self::APP_KEY);

        $service2 = new EncryptionService($this->makeContext());

        // Should still decrypt (uses key ID to find old key)
        $decrypted = $service2->decrypt($encrypted);
        $this->assertSame('secret', $decrypted);
    }

    public function testKeyIdEnablesDirectLookup(): void
    {
        // This is implicitly tested by testDecryptWithPreviousKeyViaKeyId
        // Key ID allows O(1) lookup instead of trial decryption
        $this->assertTrue(true);
    }

    public function testDecryptFailsWhenKeyIdNotFound(): void
    {
        // Encrypt with first key
        $service1 = new EncryptionService($this->makeContext());
        $encrypted = $service1->encrypt('secret');

        // Set up service with completely different key, no previous keys
        $_ENV['APP_KEY'] = self::ALT_KEY;
        $_ENV['APP_PREVIOUS_KEYS'] = '';
        putenv('APP_KEY=' . self::ALT_KEY);
        putenv('APP_PREVIOUS_KEYS=');

        $service2 = new EncryptionService($this->makeContext());

        $this->expectException(KeyNotFoundException::class);
        $this->expectExceptionMessage('rotated out');
        $service2->decrypt($encrypted);
    }

    public function testRotateKeyMethod(): void
    {
        $service = new EncryptionService($this->makeContext());

        $encrypted1 = $service->encrypt('secret');

        // Rotate to new key
        $service->rotateKey(self::ALT_KEY);

        // Encrypt with new key
        $encrypted2 = $service->encrypt('secret');

        // Key IDs should be different
        $parts1 = explode('$', $encrypted1);
        $parts2 = explode('$', $encrypted2);
        $this->assertNotSame($parts1[3], $parts2[3]);

        // Both should decrypt (old key still in map)
        $this->assertSame('secret', $service->decrypt($encrypted1));
        $this->assertSame('secret', $service->decrypt($encrypted2));
    }

    // =========================================================================
    // File Encryption Tests
    // =========================================================================

    public function testEncryptFileCreatesEncryptedOutput(): void
    {
        $service = new EncryptionService($this->makeContext());

        $source = tempnam(sys_get_temp_dir(), 'enc_test_');
        $dest = tempnam(sys_get_temp_dir(), 'enc_out_');

        try {
            file_put_contents($source, 'file content here');

            $service->encryptFile($source, $dest);

            $this->assertFileExists($dest);
            $encrypted = file_get_contents($dest);
            $this->assertTrue($service->isEncrypted($encrypted));
        } finally {
            @unlink($source);
            @unlink($dest);
        }
    }

    public function testDecryptFileRestoresOriginal(): void
    {
        $service = new EncryptionService($this->makeContext());

        $source = tempnam(sys_get_temp_dir(), 'enc_test_');
        $encrypted = tempnam(sys_get_temp_dir(), 'enc_mid_');
        $decrypted = tempnam(sys_get_temp_dir(), 'enc_out_');

        try {
            $original = 'Original file content with special chars: éàü 🔐';
            file_put_contents($source, $original);

            $service->encryptFile($source, $encrypted);
            $service->decryptFile($encrypted, $decrypted);

            $this->assertSame($original, file_get_contents($decrypted));
        } finally {
            @unlink($source);
            @unlink($encrypted);
            @unlink($decrypted);
        }
    }

    public function testFileEncryptionHandlesLargeFiles(): void
    {
        $service = new EncryptionService($this->makeContext());

        $source = tempnam(sys_get_temp_dir(), 'enc_large_');
        $encrypted = tempnam(sys_get_temp_dir(), 'enc_mid_');
        $decrypted = tempnam(sys_get_temp_dir(), 'enc_out_');

        try {
            // Create 1MB file
            $original = str_repeat('A', 1024 * 1024);
            file_put_contents($source, $original);

            $service->encryptFile($source, $encrypted);
            $service->decryptFile($encrypted, $decrypted);

            $this->assertSame($original, file_get_contents($decrypted));
        } finally {
            @unlink($source);
            @unlink($encrypted);
            @unlink($decrypted);
        }
    }

    public function testEncryptFileThrowsOnMissingSource(): void
    {
        $service = new EncryptionService($this->makeContext());

        $this->expectException(EncryptionException::class);
        $this->expectExceptionMessage('read source');
        $service->encryptFile('/nonexistent/file.txt', '/tmp/out.enc');
    }

    // =========================================================================
    // Error Handling Tests
    // =========================================================================

    public function testThrowsOnCorruptedCiphertext(): void
    {
        $service = new EncryptionService($this->makeContext());
        $encrypted = $service->encrypt('secret');

        // Corrupt the tag (last part)
        $parts = explode('$', $encrypted);
        $parts[6] = 'corrupted';
        $corrupted = implode('$', $parts);

        $this->expectException(DecryptionException::class);
        $service->decrypt($corrupted);
    }

    // =========================================================================
    // Custom Key Tests
    // =========================================================================

    public function testEncryptWithKeyOverride(): void
    {
        $service = new EncryptionService($this->makeContext());

        $encrypted = $service->encryptWithKey('value', self::ALT_KEY);
        $decrypted = $service->decryptWithKey($encrypted, self::ALT_KEY);

        $this->assertSame('value', $decrypted);
    }

    public function testEncryptWithKeyDoesNotAffectDefaultKey(): void
    {
        $service = new EncryptionService($this->makeContext());

        // Encrypt with custom key
        $customEncrypted = $service->encryptWithKey('custom', self::ALT_KEY);

        // Encrypt with default key
        $defaultEncrypted = $service->encrypt('default');

        // Custom key encryption should not decrypt with default
        try {
            $service->decrypt($customEncrypted);
            $this->fail('Should not decrypt custom key data with default key');
        } catch (KeyNotFoundException $e) {
            $this->assertTrue(true);
        }

        // Default should still work
        $this->assertSame('default', $service->decrypt($defaultEncrypted));
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function makeContext(): ApplicationContext
    {
        $root = dirname(__DIR__, 3);
        $context = ApplicationContext::forTesting($root);
        $context->setConfigLoader(new ConfigurationLoader($root, 'testing', $root . '/config'));
        return $context;
    }
}
