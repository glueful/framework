# Encryption Service Implementation Plan

**Version:** 1.22.0+
**Status:** Implemented
**Author:** Framework Team
**Date:** 2026-01-30
**Completed:** 2026-01-31

---

## Overview

Add a framework-wide encryption service to Glueful that provides secure, easy-to-use encryption for strings, files, and database fields. Uses industry-standard AES-256-GCM with proper key management.

> **Security Note:** This service provides symmetric encryption using the application key. It is NOT end-to-end encryption - the server can decrypt all data.

### Goals

1. **Simple API** - `encrypt()`/`decrypt()` helpers that just work
2. **Secure defaults** - AES-256-GCM (authenticated encryption), no insecure options
3. **Key rotation** - Support rolling keys without breaking existing encrypted data
4. **Minimal core** - Only crypto primitives in core; integrations as extensions
5. **Zero config to start** - Uses `APP_KEY` by default

---

## Architecture: Core vs Extensions

```
┌─────────────────────────────────────────────────────────────┐
│                         CORE                                 │
│   Crypto primitives - the "how"                             │
├─────────────────────────────────────────────────────────────┤
│ • EncryptionService (AES-256-GCM)                           │
│ • encrypt() / decrypt() helpers                             │
│ • encryptFile() / decryptFile()                             │
│ • Key validation, rotation support, AAD                     │
└─────────────────────────────────────────────────────────────┘
                              │
                    depends on│
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                      EXTENSIONS                              │
│   Integration policies - the "where"                        │
├─────────────────────────────────────────────────────────────┤
│ • glueful/encryption-database    → Encrypted model cast     │
│ • glueful/encryption-uploads     → Upload encryption policy │
│ • glueful/encryption-kms         → AWS KMS / GCP KMS        │
│ • glueful/encryption-hsm         → Hardware security module │
└─────────────────────────────────────────────────────────────┘
```

### Why This Split?

| In Core | Why |
|---------|-----|
| EncryptionService | Foundational primitive, used everywhere |
| String encryption | Config secrets, tokens, general use |
| File encryption | Streaming API, no persistence opinion |
| Key rotation | Core capability, not integration-specific |

| In Extensions | Why |
|---------------|-----|
| Database cast | ORM-specific, varies by app schema |
| Upload policy | Storage-specific, compliance varies |
| KMS providers | Vendor-specific, not all apps need |
| HSM support | Specialized hardware, niche use case |

This keeps core lean (~300 lines) while allowing rich integrations via extensions.

---

## API Design

### Helper Functions

```php
// Basic encryption/decryption
$encrypted = encrypt($context, 'sensitive data');
$decrypted = decrypt($context, $encrypted);

// With AAD (Additional Authenticated Data) - prevents cross-context misuse
$encrypted = encrypt($context, $ssn, aad: 'user.ssn');
$decrypted = decrypt($context, $encrypted, aad: 'user.ssn'); // Must match!

// Check if string is encrypted
$isEncrypted = is_encrypted($string); // Checks for prefix/format
```

### AAD (Additional Authenticated Data)

AAD binds ciphertext to a context label. Decryption fails if AAD doesn't match:

```php
// Encrypt SSN with context
$encrypted = encrypt($context, '123-45-6789', aad: 'user.ssn');

// Correct context - works
decrypt($context, $encrypted, aad: 'user.ssn'); // '123-45-6789'

// Wrong context - fails (prevents moving ciphertext between fields)
decrypt($context, $encrypted, aad: 'user.api_key'); // DecryptionException!
```

**Use cases:**
- Prevent copying encrypted SSN to API key field
- Bind ciphertext to specific table/column
- Tenant isolation in multi-tenant apps

### Service API

```php
use Glueful\Encryption\EncryptionService;

$encryption = app($context, EncryptionService::class);

// String encryption (with optional AAD)
$encrypted = $encryption->encrypt('sensitive data');
$encrypted = $encryption->encrypt('sensitive data', aad: 'context.label');
$decrypted = $encryption->decrypt($encrypted, aad: 'context.label');

// Binary data encryption (handles non-UTF8 safely)
$encrypted = $encryption->encryptBinary($binaryData);
$binary = $encryption->decryptBinary($encrypted);

// File encryption
$encryption->encryptFile('/path/to/source', '/path/to/dest.enc');
$encryption->decryptFile('/path/to/source.enc', '/path/to/dest');

// Encrypt to stream (memory efficient for large files)
$encryptedStream = $encryption->encryptStream($inputStream);

// Key management
$encryption->rotateKey($newKey); // Re-encrypt with new key (batch operation)
```

### Binary-Safe Handling

| Method | Input | Output | Use Case |
|--------|-------|--------|----------|
| `encrypt()` | UTF-8 string | Base64 string | Text, JSON, etc. |
| `encryptBinary()` | Raw bytes | Base64 string | Images, files, serialized data |
| `decrypt()` | Base64 string | UTF-8 string | Expects valid UTF-8 |
| `decryptBinary()` | Base64 string | Raw bytes | Returns bytes as-is |

**Note:** `encrypt()` validates UTF-8 input and throws if invalid. Use `encryptBinary()` for arbitrary byte sequences.

### Encrypted Output Format

```
$glueful$v1$<key_id>$<nonce_base64>$<ciphertext_base64>$<tag_base64>
```

| Part | Raw Length | Base64 Length | Description |
|------|------------|---------------|-------------|
| `$glueful$` | - | 9 chars | Prefix identifier |
| `v1` | - | 2 chars | Format version |
| `key_id` | - | 8 chars | First 8 chars of SHA-256(key) |
| `nonce` | 12 bytes | 16 chars | Random nonce (unpadded base64) |
| `ciphertext` | variable | variable | Encrypted data (unpadded base64) |
| `tag` | 16 bytes | 22 chars | Auth tag (unpadded base64) |

**Base64 encoding:** Uses **URL-safe, unpadded base64** (`base64url` per RFC 4648 §5, no `=` padding). This avoids issues with `+`, `/`, and padding chars in URLs and storage.

### Empty String Handling

Empty strings **are allowed** and encrypt to a valid payload:

```php
$encrypted = encrypt($context, '');  // Valid - produces ~60 byte output
$decrypted = decrypt($context, $encrypted);  // Returns ''
```

The ciphertext for empty input is 0 bytes, but nonce + tag + overhead still produce output:
```
$glueful$v1$abc12345$QUJDREVGR0hJSktM$$tbWV0YWRhdGFoZXJl
                                      ^^ empty ciphertext
```

This format:
- Is self-identifying (easy to detect encrypted strings)
- Includes version for future-proofing
- **Key ID enables O(1) key lookup** during rotation (no trial decryption)
- Contains all data needed for decryption

### Size Overhead

| Input Size | Output Size | Overhead |
|------------|-------------|----------|
| 0 bytes | ~70 bytes | Fixed overhead |
| 100 bytes | ~210 bytes | ~2.1x |
| 1 KB | ~1.45 KB | ~1.45x |
| 10 KB | ~13.7 KB | ~1.37x |

**Formula:** `output ≈ 70 + ceil(input_length * 1.37)` bytes

**Database column recommendation:** For a field expecting max N bytes of plaintext, use `VARCHAR(70 + ceil(N * 1.4))` or simply `TEXT`.

---

## Configuration

### config/encryption.php

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | The encryption key must be 32 bytes (256 bits) for AES-256.
    | Generate with: php glueful generate:key
    | Store securely - losing this key means losing access to encrypted data.
    |
    */
    'key' => env('APP_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Cipher Algorithm
    |--------------------------------------------------------------------------
    |
    | AES-256-GCM is the only supported cipher. It provides:
    | - 256-bit encryption (AES-256)
    | - Authenticated encryption (GCM mode)
    | - Protection against tampering
    |
    */
    'cipher' => 'aes-256-gcm',

    /*
    |--------------------------------------------------------------------------
    | Previous Keys (Key Rotation)
    |--------------------------------------------------------------------------
    |
    | When rotating keys, add old keys here. Decryption will try the current
    | key first, then fall back to previous keys. Old keys are only used
    | for decryption, never for new encryption.
    |
    */
    'previous_keys' => array_filter(
        explode(',', env('APP_PREVIOUS_KEYS', ''))
    ),

    /*
    |--------------------------------------------------------------------------
    | File Encryption
    |--------------------------------------------------------------------------
    |
    | Settings for file encryption operations.
    |
    */
    'files' => [
        // Chunk size for streaming encryption (memory efficient)
        'chunk_size' => 64 * 1024, // 64KB

        // Extension added to encrypted files
        'extension' => '.enc',

        // Delete source file after successful encryption
        'delete_source' => false,
    ],

];
```

---

## Implementation Details

### 1. EncryptionService

```php
<?php

namespace Glueful\Encryption;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Encryption\Exceptions\DecryptionException;
use Glueful\Encryption\Exceptions\EncryptionException;

class EncryptionService
{
    private const PREFIX = '$glueful$';
    private const VERSION = 'v1';
    private const NONCE_LENGTH = 12;
    private const TAG_LENGTH = 16;
    private const KEY_LENGTH = 32; // 256 bits
    private const KEY_ID_LENGTH = 8;
    private const CIPHER = 'aes-256-gcm';

    private string $key;
    private string $keyId;
    /** @var array<string, string> Map of keyId => key */
    private array $keyMap = [];
    private ApplicationContext $context;

    public function __construct(ApplicationContext $context)
    {
        $this->context = $context;

        // Resolve and validate primary key
        $this->key = $this->resolveAndValidateKey(
            config($context, 'encryption.key')
        );
        $this->keyId = $this->deriveKeyId($this->key);
        $this->keyMap[$this->keyId] = $this->key;

        // Resolve previous keys for rotation
        foreach (config($context, 'encryption.previous_keys', []) as $prevKey) {
            $resolved = $this->resolveAndValidateKey($prevKey);
            $keyId = $this->deriveKeyId($resolved);
            $this->keyMap[$keyId] = $resolved;
        }
    }

    /**
     * Resolve key from config and validate length
     * @throws InvalidKeyException
     */
    private function resolveAndValidateKey(?string $key): string
    {
        if ($key === null || $key === '') {
            throw new InvalidKeyException(
                'Encryption key not configured. Run: php glueful generate:key'
            );
        }

        // Handle base64: prefix
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
            if ($key === false) {
                throw new InvalidKeyException('Invalid base64 encoding in encryption key');
            }
        }

        // Strict length validation - fail fast
        if (strlen($key) !== self::KEY_LENGTH) {
            throw new InvalidKeyException(sprintf(
                'Encryption key must be exactly %d bytes, got %d bytes',
                self::KEY_LENGTH,
                strlen($key)
            ));
        }

        return $key;
    }

    /**
     * Derive 8-char key ID from key (for O(1) lookup)
     */
    private function deriveKeyId(string $key): string
    {
        return substr(hash('sha256', $key), 0, self::KEY_ID_LENGTH);
    }

    /**
     * Encrypt a string value
     *
     * @param string $value UTF-8 string to encrypt
     * @param string $aad Additional Authenticated Data (context binding)
     * @throws EncryptionException
     */
    public function encrypt(string $value, string $aad = ''): string
    {
        // Validate UTF-8 for encrypt() - use encryptBinary() for raw bytes
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new EncryptionException(
                'Input is not valid UTF-8. Use encryptBinary() for raw bytes.'
            );
        }

        return $this->encryptRaw($value, $aad);
    }

    /**
     * Encrypt binary data (non-UTF8 safe)
     */
    public function encryptBinary(string $value, string $aad = ''): string
    {
        return $this->encryptRaw($value, $aad);
    }

    private function encryptRaw(string $value, string $aad): string
    {
        $nonce = random_bytes(self::NONCE_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $value,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad, // AAD bound to ciphertext
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new EncryptionException('Encryption failed: ' . openssl_error_string());
        }

        return $this->formatOutput($this->keyId, $nonce, $ciphertext, $tag);
    }

    /**
     * Decrypt an encrypted string
     *
     * @param string $encrypted Encrypted payload
     * @param string $aad AAD must match what was used during encryption
     * @throws DecryptionException
     */
    public function decrypt(string $encrypted, string $aad = ''): string
    {
        $parts = $this->parseEncrypted($encrypted);

        // O(1) key lookup using key ID
        $key = $this->keyMap[$parts['keyId']] ?? null;

        if ($key === null) {
            throw new DecryptionException(
                'Unknown key ID - key may have been rotated out'
            );
        }

        $decrypted = $this->attemptDecrypt($parts, $key, $aad);

        if ($decrypted === null) {
            throw new DecryptionException(
                'Decryption failed - wrong key, wrong AAD, or corrupted data'
            );
        }

        return $decrypted;
    }

    /**
     * Decrypt binary data
     */
    public function decryptBinary(string $encrypted, string $aad = ''): string
    {
        return $this->decrypt($encrypted, $aad);
    }

    /**
     * Encrypt a file
     */
    public function encryptFile(string $sourcePath, string $destPath): void
    {
        // Stream-based encryption for memory efficiency
        // Uses chunk_size from config
    }

    /**
     * Decrypt a file
     */
    public function decryptFile(string $sourcePath, string $destPath): void
    {
        // Stream-based decryption
    }

    /**
     * Check if a string appears to be encrypted
     */
    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    // Private helper methods...
}
```

### 2. Encryption Flow

```
encrypt('secret data')

┌─────────────────────────────────────────────────────────────┐
│                    EncryptionService::encrypt()              │
├─────────────────────────────────────────────────────────────┤
│ 1. Generate 12-byte random nonce (IV)                       │
│ 2. Encrypt with AES-256-GCM:                                │
│    ├─ Algorithm: aes-256-gcm                                │
│    ├─ Key: APP_KEY (32 bytes)                               │
│    ├─ Nonce: random 12 bytes                                │
│    └─ Output: ciphertext + 16-byte auth tag                 │
│ 3. Format output:                                           │
│    $glueful$v1${nonce_b64}${ciphertext_b64}${tag_b64}      │
│ 4. Return formatted string                                  │
└─────────────────────────────────────────────────────────────┘
```

### 3. Decryption Flow

```
decrypt('$glueful$v1$...')

┌─────────────────────────────────────────────────────────────┐
│                    EncryptionService::decrypt()              │
├─────────────────────────────────────────────────────────────┤
│ 1. Validate format (starts with $glueful$)                  │
│ 2. Parse version, nonce, ciphertext, tag                    │
│ 3. Attempt decrypt with current key:                        │
│    ├─ Verify auth tag (tampering protection)                │
│    └─ If success: return plaintext                          │
│ 4. If failed, try previous_keys (key rotation support)      │
│ 5. If all keys fail: throw DecryptionException              │
└─────────────────────────────────────────────────────────────┘
```

### 4. File Encryption Flow

**Nonce Strategy:** Counter-based derivation from base nonce (safe for GCM).

```
encryptFile('/path/to/large-file.pdf', '/path/to/output.enc')

┌─────────────────────────────────────────────────────────────┐
│                EncryptionService::encryptFile()              │
├─────────────────────────────────────────────────────────────┤
│ 1. Generate 8-byte random base nonce                        │
│ 2. Write header:                                            │
│    ├─ Magic bytes: "GFENC"                                  │
│    ├─ Version: 0x01                                         │
│    ├─ Key ID: 8 bytes (for rotation)                        │
│    └─ Base nonce: 8 bytes                                   │
│ 3. Stream chunks (64KB default):                            │
│    FOR each chunk (i = 0, 1, 2, ...):                       │
│    ├─ Derive chunk nonce: base_nonce || i (12 bytes total) │
│    ├─ Encrypt chunk with AES-256-GCM + derived nonce        │
│    ├─ Write: chunk_length (4 bytes) + ciphertext + tag      │
│    └─ i++ (counter prevents nonce reuse)                    │
│ 4. Write final chunk with length=0 (EOF marker)             │
│ 5. Optionally delete source file                            │
└─────────────────────────────────────────────────────────────┘
```

**GCM Nonce Safety:**
- Base nonce (8 bytes) is random per file
- Chunk counter (4 bytes, big-endian) appended to form 12-byte nonce
- Max chunks: 2^32 = 4 billion × 64KB = 256 PB per file (safe limit)
- **Never reuse key+nonce pair** - counter ensures uniqueness within file

**Nonce derivation example:**
```
Base nonce (random):  0xA1B2C3D4E5F60708 (8 bytes)
Chunk 0 nonce:        0xA1B2C3D4E5F60708 || 0x00000000 = 12 bytes
Chunk 1 nonce:        0xA1B2C3D4E5F60708 || 0x00000001 = 12 bytes
Chunk 2 nonce:        0xA1B2C3D4E5F60708 || 0x00000002 = 12 bytes
...
```

This is a **counter mode construction** (not random-per-chunk), which is safe for GCM because:
1. Each chunk gets a unique nonce (counter guarantees no collision)
2. Base nonce is random per file (different files never share nonce space)
3. Same key never sees same nonce twice (assuming no file >256 PB)

---

## Database Integration (Extension: `glueful/encryption-database`)

> **Note:** This section describes the `glueful/encryption-database` extension, not core functionality. It shows how extensions can build on the core EncryptionService.

### Encrypted Cast

```php
// In your model
use Glueful\Database\Casts\Encrypted;

class User extends Model
{
    protected array $casts = [
        'ssn' => Encrypted::class,
        'api_secret' => Encrypted::class,
    ];
}

// Usage - encryption/decryption is automatic
$user->ssn = '123-45-6789';  // Encrypted before INSERT
$user->save();

echo $user->ssn;  // '123-45-6789' (decrypted on read)
```

### Encrypted Cast Implementation

```php
<?php

namespace Glueful\Database\Casts;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Encryption\EncryptionService;

class Encrypted implements CastInterface
{
    private string $table;
    private string $column;

    public function __construct(string $table, string $column)
    {
        $this->table = $table;
        $this->column = $column;
    }

    /**
     * Generate AAD from table.column to prevent cross-field attacks
     */
    private function getAad(): string
    {
        return "{$this->table}.{$this->column}";
    }

    public function get(mixed $value, ApplicationContext $context): ?string
    {
        if ($value === null) {
            return null;
        }

        $encryption = app($context, EncryptionService::class);

        if (!$encryption->isEncrypted($value)) {
            return $value; // Return as-is if not encrypted (migration period)
        }

        return $encryption->decrypt($value, aad: $this->getAad());
    }

    public function set(mixed $value, ApplicationContext $context): ?string
    {
        if ($value === null) {
            return null;
        }

        $encryption = app($context, EncryptionService::class);
        return $encryption->encrypt($value, aad: $this->getAad());
    }
}
```

**AAD auto-binding:** The cast automatically uses `{table}.{column}` as AAD. This means:
- Encrypted SSN from `users.ssn` cannot be copied to `users.api_key`
- Even if attacker swaps ciphertext, decryption fails (AAD mismatch)

### Database Considerations

| Consideration | Recommendation |
|---------------|----------------|
| Column type | `TEXT` or `VARCHAR(N)` based on sizing formula below |
| Indexing | Cannot index encrypted columns (searching requires decryption) |
| Querying | Use deterministic hashing for lookups (see Searchable Encryption below) |
| AAD | Auto-generated from `{table}.{column}` to prevent cross-field attacks |

### Column Sizing Guide

| Plaintext Max | Recommended Column |
|---------------|-------------------|
| 16 bytes (UUID) | `VARCHAR(100)` |
| 50 bytes (phone) | `VARCHAR(150)` |
| 100 bytes (email) | `VARCHAR(220)` |
| 255 bytes (short text) | `VARCHAR(430)` |
| 1 KB | `VARCHAR(1500)` or `TEXT` |
| > 1 KB | `TEXT` |

**Formula:** `column_size = 70 + ceil(plaintext_max * 1.4)`

---

## Upload Integration (Extension: `glueful/encryption-uploads`)

> **Note:** This section describes the `glueful/encryption-uploads` extension, not core functionality.

Optional encryption for uploaded files:

```php
// config/uploads.php
return [
    'encryption' => [
        'enabled' => env('UPLOADS_ENCRYPTION', false),
        'types' => ['application/*', 'text/*'], // Encrypt these MIME types
        'exclude_types' => ['image/*', 'video/*'], // Don't encrypt (need processing)
    ],
];
```

When enabled:
- Matching files are encrypted before storage
- Decrypted on retrieval
- **Image resize disabled for encrypted files** (cannot process encrypted images)
- Metadata (filename, MIME type) stored unencrypted in blob record

---

## Key Management

### Generating Keys

```bash
# Generate a new 256-bit key
php glueful generate:key

# Output:
# APP_KEY=base64:AbCdEfGhIjKlMnOpQrStUvWxYz012345678901234=
```

### Key Format

Keys can be provided as:
- `base64:...` - Base64-encoded 32-byte key (recommended)
- Raw 32-byte string (not recommended - encoding issues)

### Key Rotation

1. Generate new key
2. Add old key to `APP_PREVIOUS_KEYS`
3. Deploy with new `APP_KEY`
4. Run re-encryption job (optional, for cleanup)

```env
# .env
APP_KEY=base64:NewKeyHere...
APP_PREVIOUS_KEYS=base64:OldKey1...,base64:OldKey2...
```

```bash
# Optional: Re-encrypt all data with new key
php glueful encryption:rotate --table=users --columns=ssn,api_secret
```

---

## Security Considerations

1. **AES-256-GCM only** - No option for weaker algorithms
2. **Authenticated encryption** - GCM mode detects tampering
3. **Random nonces** - Each encryption uses unique nonce (no nonce reuse)
4. **Key derivation** - Raw key used directly (consider HKDF for future)
5. **Timing attacks** - Use `hash_equals()` for comparisons
6. **Memory safety** - Clear sensitive data from memory when possible
7. **Key storage** - Keys in env vars, not in code or database

### What This Does NOT Provide

| Not Provided | Why |
|--------------|-----|
| End-to-end encryption | Server holds the key |
| Searchable encryption | Would require deterministic encryption (weaker) |
| Key escrow/recovery | Lost key = lost data |
| HSM integration | Out of scope for v1 |

---

## Error Handling

### Exceptions

```php
namespace Glueful\Encryption\Exceptions;

// Base exception
class EncryptionException extends \RuntimeException {}

// Specific exceptions
class DecryptionException extends EncryptionException {}
class InvalidKeyException extends EncryptionException {}
class KeyNotFoundException extends EncryptionException {}
```

### Error Codes

| Exception | When | Recovery |
|-----------|------|----------|
| `InvalidKeyException` | Key wrong length or format | Fix APP_KEY in .env |
| `KeyNotFoundException` | No APP_KEY configured | Generate key with `generate:key` |
| `DecryptionException` | Wrong key or corrupted data | Check key, check data integrity |
| `EncryptionException` | OpenSSL failure | Check PHP OpenSSL extension |

---

## Files to Create

### Core (this implementation)

| File | Purpose |
|------|---------|
| `config/encryption.php` | Configuration |
| `src/Encryption/EncryptionService.php` | Core encryption logic |
| `src/Encryption/Exceptions/EncryptionException.php` | Base exception |
| `src/Encryption/Exceptions/DecryptionException.php` | Decryption failures |
| `src/Encryption/Exceptions/InvalidKeyException.php` | Key validation |
| `src/helpers.php` (modify) | Add `encrypt()`, `decrypt()` helpers |

### Extensions (separate packages, not part of this plan)

| Package | Files | Purpose |
|---------|-------|---------|
| `glueful/encryption-database` | `Encrypted.php` cast | Model field encryption |
| `glueful/encryption-uploads` | Upload middleware | Encrypt files on upload |
| `glueful/encryption-kms` | KMS providers | AWS/GCP key management |

---

## Testing Plan

### Unit Tests

**Core Encryption:**
- [x] `testEncryptReturnsFormattedString`
- [x] `testDecryptReturnsOriginalValue`
- [x] `testEncryptDecryptRoundTrip`
- [x] `testEncryptProducesDifferentOutputEachTime` (random nonce)
- [x] `testDecryptFailsWithWrongKey`
- [x] `testDecryptFailsWithTamperedData`
- [x] `testDecryptFailsWithInvalidFormat`
- [x] `testIsEncryptedDetectsEncryptedStrings`
- [x] `testOutputContainsKeyId`

**AAD (Additional Authenticated Data):**
- [x] `testEncryptWithAadDecryptsWithSameAad`
- [x] `testDecryptFailsWithWrongAad`
- [x] `testDecryptFailsWithMissingAadWhenRequired`
- [x] `testAadPreventsContextSwapping`

**Key Validation:**
- [x] `testThrowsOnMissingKey`
- [x] `testThrowsOnKeyTooShort`
- [x] `testThrowsOnKeyTooLong`
- [x] `testAcceptsBase64PrefixedKey`
- [x] `testThrowsOnInvalidBase64Key`

**Binary Handling:**
- [x] `testEncryptBinaryHandlesNonUtf8`
- [x] `testEncryptThrowsOnNonUtf8Input`
- [x] `testDecryptBinaryReturnsRawBytes`

**Key Rotation:**
- [x] `testDecryptWithPreviousKeyViaKeyId`
- [x] `testKeyIdEnablesDirectLookup`
- [x] `testDecryptFailsWhenKeyIdNotFound`

**File Encryption:**
- [x] `testEncryptFileCreatesEncryptedOutput`
- [x] `testDecryptFileRestoresOriginal`
- [x] `testFileEncryptionHandlesLargeFiles`
- [ ] `testFileEncryptionStreamingMemoryEfficient` (streaming API not yet implemented)

**Error Handling:**
- [x] `testThrowsOnMissingKey`
- [x] `testThrowsOnInvalidKeyLength`
- [x] `testThrowsOnCorruptedCiphertext`

### Integration Tests (Core)

- [x] Full encrypt → decrypt round-trip
- [x] Key rotation without data loss
- [x] File encrypt → decrypt round-trip
- [ ] Large file streaming encryption (memory efficiency) (streaming API not yet implemented)

### Extension Tests (separate packages)

- [ ] `encryption-database`: Model cast encrypt/decrypt
- [ ] `encryption-uploads`: Upload encryption policy
- [ ] `encryption-kms`: KMS key resolution

---

## CLI Commands

```bash
# Generate encryption key
php glueful generate:key

# Verify encryption is working
php glueful encryption:test

# Rotate keys in database (re-encrypt with new key)
php glueful encryption:rotate --table=users --columns=ssn,api_key

# Encrypt a file
php glueful encryption:file encrypt /path/to/file

# Decrypt a file
php glueful encryption:file decrypt /path/to/file.enc
```

---

## Future Enhancements

1. **Searchable encryption** - Blind index for lookups without decryption
2. **Asymmetric encryption** - RSA for key exchange scenarios
3. **HSM support** - Hardware security module integration
4. **Envelope encryption** - Data keys wrapped by master key (AWS KMS pattern)
5. **Audit logging** - Log encryption/decryption operations

---

## Related Documentation

- [OpenSSL GCM Mode](https://www.php.net/manual/en/function.openssl-encrypt.php)
- [OWASP Cryptographic Storage](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html)
- [NIST SP 800-38D (GCM)](https://csrc.nist.gov/publications/detail/sp/800-38d/final)
