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

    /*
    |--------------------------------------------------------------------------
    | Database Encryption
    |--------------------------------------------------------------------------
    |
    | Settings for encrypted database field casting.
    |
    */
    'database' => [
        // Enable encrypted cast type
        'enabled' => true,

        // Column type recommendation: TEXT or BLOB (encrypted data is larger)
        'recommended_column_type' => 'TEXT',
    ],
];
