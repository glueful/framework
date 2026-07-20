<?php

declare(strict_types=1);

namespace Glueful\Auth\ApiKey;

use Glueful\Auth\ApiKey\Exceptions\ApiKeyExpiredException;
use Glueful\Auth\ApiKey\Exceptions\InvalidApiKeyException;
use Glueful\Auth\ApiKey\Support\CidrMatcher;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\Database\EntityCreatedEvent;
use Glueful\Events\Database\EntityUpdatedEvent;
use Glueful\Events\EventService;
use Glueful\Helpers\Utils;

/**
 * Generation, verification, rotation, and revocation for API keys.
 *
 * Key format: gf_live_<32-char-base62> in production, gf_test_<32-char-base62>
 * elsewhere. The first 16 chars are stored as the indexed lookup column;
 * the full key is SHA-256 hashed and stored separately. See:
 *   docs/superpowers/specs/2026-05-21-api-key-hardening-design.md
 */
final class ApiKeyService
{
    private const PREFIX_LENGTH = 16;
    private const RANDOM_LENGTH = 32;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    // ── Pure helpers (no DB I/O) ──

    public static function generatePlainKey(string $environment, string $brand = 'gf'): string
    {
        $env = $environment === 'production' ? 'live' : 'test';
        $prefix = $brand . '_' . $env . '_';
        $random = '';
        $alphabetLength = strlen(self::ALPHABET);
        for ($i = 0; $i < self::RANDOM_LENGTH; $i++) {
            $random .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
        }
        return $prefix . $random;
    }

    public static function extractPrefix(string $plainKey): string
    {
        return substr($plainKey, 0, self::PREFIX_LENGTH);
    }

    public static function hashKey(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    /**
     * fnmatch-style scope check. Empty $grantedScopes = full access.
     *
     * @param array<int, string> $grantedScopes
     */
    public static function scopeSatisfies(array $grantedScopes, string $required): bool
    {
        if ($grantedScopes === []) {
            return true;
        }

        foreach ($grantedScopes as $granted) {
            if (fnmatch($granted, $required)) {
                return true;
            }
        }

        return false;
    }

    // ── DB-touching operations ──

    /**
     * Create a new API key. Returns the plaintext key ONCE; never stored.
     *
     * @param array{
     *     user_uuid: string,
     *     name: string,
     *     scopes?: array<int, string>|null,
     *     allowed_ips?: array<int, string>|null,
     *     expires_at?: string|null,
     * } $attrs
     * @return array{plain: string, key: ApiKey}
     */
    public static function create(ApplicationContext $context, array $attrs): array
    {
        $env = $context->getEnvironment();
        $plain = self::generatePlainKey($env, self::brand($context));

        $scopes = $attrs['scopes'] ?? null;
        $allowed = $attrs['allowed_ips'] ?? null;

        $key = new ApiKey([
            'uuid'        => Utils::generateNanoID(),
            'user_uuid'   => $attrs['user_uuid'],
            'name'        => $attrs['name'],
            'key_prefix'  => self::extractPrefix($plain),
            'key_hash'    => self::hashKey($plain),
            'scopes'      => is_array($scopes) ? json_encode(array_values($scopes)) : null,
            'allowed_ips' => is_array($allowed) ? json_encode(array_values($allowed)) : null,
            'expires_at'  => $attrs['expires_at'] ?? null,
        ], $context);

        $key->save();

        // Audit trail: emit a generic entity event (the audit extension records it). Identity only —
        // never the plaintext or key_hash. See self::auditView().
        self::dispatchEvent($context, new EntityCreatedEvent(self::auditView($key), 'api_keys'));

        return ['plain' => $plain, 'key' => $key];
    }

    /**
     * Verify a plaintext key against the api_keys table.
     *
     * Exception contract:
     *   - ApiKeyExpiredException when a row matched (hash equal) but is
     *     past expires_at. Distinct so callers can produce a specific
     *     "your key expired" diagnostic.
     *   - InvalidApiKeyException for anything else — no prefix match, no
     *     hash match across all prefix candidates, revoked, or IP not in
     *     allowed_ips. The provider catches this and returns null with a
     *     generic error (don't leak which check failed).
     *
     * Prefix lookup is collision-tolerant: fetches ALL rows for the prefix
     * and hash_equals each. The UNIQUE constraint on key_hash is the
     * actual uniqueness guarantee; key_prefix is indexed only.
     */
    public static function verify(
        ApplicationContext $context,
        string $plainKey,
        string $clientIp
    ): ApiKey {
        $prefix = self::extractPrefix($plainKey);
        $expectedHash = self::hashKey($plainKey);

        $candidates = ApiKey::query($context)
            ->where('key_prefix', '=', $prefix)
            ->get();

        $matched = null;
        foreach ($candidates as $row) {
            // ApiKey::query() returns a Collection<Model>; assert the row
            // is actually an ApiKey so callers (and static analysis) get
            // the typed model rather than the abstract Model base.
            if (!$row instanceof ApiKey) {
                continue;
            }
            if (hash_equals((string) ($row->key_hash ?? ''), $expectedHash)) {
                $matched = $row;
                break;
            }
        }

        if ($matched === null) {
            throw new InvalidApiKeyException('Invalid API key');
        }

        if ($matched->isRevoked()) {
            throw new InvalidApiKeyException('Invalid API key');
        }

        if ($matched->isExpired()) {
            throw new ApiKeyExpiredException('Expired API key');
        }

        $allowed = $matched->getAllowedIps();
        if ($allowed !== [] && !CidrMatcher::matchesAny($clientIp, $allowed)) {
            throw new InvalidApiKeyException('Invalid API key');
        }

        return $matched;
    }

    /**
     * Rotate a key with a grace period. Both old and new keys are valid
     * during the grace window.
     *
     * The predecessor's expiry is bounded to the EARLIER of its existing `expires_at` and the
     * grace deadline (`now + graceHours`) — rotation must never EXTEND a predecessor's expiry.
     * A null existing expiry is treated as "no earlier bound", so the grace deadline applies.
     * The successor key is unaffected by this clamp — it inherits `$existing->expires_at` as
     * captured before the predecessor's expiry is shortened.
     *
     * @return array{old_uuid: string, new_uuid: string, new_plain: string, old_expires_at: string}
     */
    public static function rotate(
        ApplicationContext $context,
        ApiKey $existing,
        int $graceHours = 24
    ): array {
        $env = $context->getEnvironment();
        $newPlain = self::generatePlainKey($env, self::brand($context));

        $newKey = new ApiKey([
            'uuid'            => Utils::generateNanoID(),
            'user_uuid'       => $existing->user_uuid,
            'name'            => $existing->name . ' (rotated)',
            'key_prefix'      => self::extractPrefix($newPlain),
            'key_hash'        => self::hashKey($newPlain),
            'scopes'          => $existing->scopes,
            'allowed_ips'     => $existing->allowed_ips,
            'expires_at'      => $existing->expires_at,
            'rotated_from_id' => $existing->id,
        ], $context);
        $newKey->save();

        $graceDeadlineTs = time() + ($graceHours * 3600);
        $existingExpiresAt = $existing->expires_at ?? null;
        $existingExpiryTs = is_string($existingExpiresAt) && $existingExpiresAt !== ''
            ? strtotime($existingExpiresAt)
            : false;

        // Never extend: if the predecessor already expires sooner than the grace deadline, keep
        // its earlier expiry. Otherwise (later expiry, or none at all) shorten it to the deadline.
        $appliedExpiryTs = ($existingExpiryTs !== false && $existingExpiryTs < $graceDeadlineTs)
            ? $existingExpiryTs
            : $graceDeadlineTs;

        $newExpiry = date('Y-m-d H:i:s', $appliedExpiryTs);
        $existing->expires_at = $newExpiry;
        $existing->save();

        // Audit: the successor key is created, and the old key's expiry is bounded to the earlier
        // of its own expiry and the grace deadline.
        self::dispatchEvent($context, new EntityCreatedEvent(self::auditView($newKey), 'api_keys'));
        self::dispatchEvent($context, new EntityUpdatedEvent(
            self::auditView($existing),
            'api_keys',
            ['expires_at' => $newExpiry],
        ));

        return [
            'old_uuid'       => $existing->uuid,
            'new_uuid'       => $newKey->uuid,
            'new_plain'      => $newPlain,
            'old_expires_at' => $newExpiry,
        ];
    }

    public static function revoke(ApplicationContext $context, ApiKey $key): void
    {
        $revokedAt = date('Y-m-d H:i:s');
        $key->revoked_at = $revokedAt;
        $key->save();

        self::dispatchEvent($context, new EntityUpdatedEvent(
            self::auditView($key),
            'api_keys',
            ['revoked_at' => $revokedAt],
        ));
    }

    /** Brand segment of generated keys (auth.api_keys.prefix; default 'gf'). */
    private static function brand(ApplicationContext $context): string
    {
        $brand = config($context, 'auth.api_keys.prefix', 'gf');

        return is_string($brand) && $brand !== '' ? $brand : 'gf';
    }

    /**
     * Identity-only view of a key for audit/event payloads. NEVER includes the plaintext or the
     * key_hash — a key lifecycle event must not leak the credential or its hash into the audit log.
     *
     * @return array<string,mixed>
     */
    private static function auditView(ApiKey $key): array
    {
        return [
            'uuid'        => $key->uuid,
            'user_uuid'   => $key->user_uuid,
            'name'        => $key->name,
            'key_prefix'  => $key->key_prefix,
            'scopes'      => $key->scopes,
            'allowed_ips' => $key->allowed_ips,
            'expires_at'  => $key->expires_at,
            'revoked_at'  => $key->revoked_at,
        ];
    }

    /** Best-effort event dispatch — auditing must never break a key lifecycle operation. */
    private static function dispatchEvent(ApplicationContext $context, object $event): void
    {
        try {
            app($context, EventService::class)->dispatch($event);
        } catch (\Throwable) {
            // Swallow: the key was already persisted; a failed audit dispatch must not surface.
        }
    }

    /** @return array<int, ApiKey> */
    public static function forUser(ApplicationContext $context, string $userId): array
    {
        $rows = ApiKey::query($context)
            ->where('user_uuid', '=', $userId)
            ->get();

        // Filter to typed ApiKey instances — ApiKey::query()->get() returns
        // a generic Collection<Model> as far as static analysis is concerned.
        $keys = [];
        foreach ($rows as $row) {
            if ($row instanceof ApiKey) {
                $keys[] = $row;
            }
        }
        return $keys;
    }
}
