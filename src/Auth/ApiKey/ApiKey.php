<?php

declare(strict_types=1);

namespace Glueful\Auth\ApiKey;

use Glueful\Database\ORM\Model;

/**
 * ApiKey ORM model on the api_keys table.
 *
 * Use ApiKeyService for generation, verification, rotation, and revocation —
 * this class is the data carrier; the service is the business logic.
 *
 * @property int|null    $id
 * @property string      $uuid
 * @property string      $user_uuid
 * @property string      $name
 * @property string      $key_prefix
 * @property string      $key_hash
 * @property string|null $scopes
 * @property string|null $allowed_ips
 * @property string|null $expires_at
 * @property int|null    $rotated_from_id
 * @property string|null $revoked_at
 * @property string|null $created_at
 * @property string|null $updated_at
 */
final class ApiKey extends Model
{
    protected string $table = 'api_keys';

    /**
     * Database fills created_at / updated_at via DEFAULT CURRENT_TIMESTAMP.
     * Disabling the ORM's automatic timestamp population avoids passing
     * DateTimeImmutable objects through the QueryBuilder string-bind path.
     */
    public bool $timestamps = false;

    /** @var array<string> */
    protected array $fillable = [
        'uuid',
        'user_uuid',
        'name',
        'key_prefix',
        'key_hash',
        'scopes',
        'allowed_ips',
        'expires_at',
        'rotated_from_id',
        'revoked_at',
    ];

    /**
     * Decoded scopes array (or empty if the key has no scope restriction —
     * empty list is treated as full access by ApiKeyService::scopeSatisfies).
     *
     * @return array<int, string>
     */
    public function getScopes(): array
    {
        $raw = $this->scopes ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /**
     * Decoded allowed_ips array (or empty if no restriction).
     *
     * @return array<int, string>
     */
    public function getAllowedIps(): array
    {
        $raw = $this->allowed_ips ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    public function isExpired(): bool
    {
        $expiresAt = $this->expires_at ?? null;
        if (!is_string($expiresAt) || $expiresAt === '') {
            return false;
        }
        $ts = strtotime($expiresAt);
        return $ts !== false && $ts < time();
    }

    public function isRevoked(): bool
    {
        return ($this->revoked_at ?? null) !== null;
    }
}
