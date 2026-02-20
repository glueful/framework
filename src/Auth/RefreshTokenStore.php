<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;
use PDO;

/**
 * Refresh Token Store
 *
 * Stores and rotates refresh tokens in a dedicated table using token hashes.
 * This is intentionally separate from auth_sessions to support one-time use
 * rotation and replay detection.
 */
class RefreshTokenStore
{
    private Connection $db;

    public function __construct(
        ?Connection $db = null,
        ?ApplicationContext $context = null
    ) {
        $this->db = $db ?? Connection::fromContext($context);
    }

    public function issue(
        string $sessionUuid,
        string $userUuid,
        string $refreshToken,
        int $ttlSeconds,
        ?string $parentUuid = null
    ): bool {
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + max(1, $ttlSeconds));

        $row = [
            'uuid' => Utils::generateNanoID(),
            'session_uuid' => $sessionUuid,
            'user_uuid' => $userUuid,
            'token_hash' => $this->hashToken($refreshToken),
            'status' => 'active',
            'parent_uuid' => $parentUuid,
            'replaced_by_uuid' => null,
            'issued_at' => $now,
            'expires_at' => $expiresAt,
            'consumed_at' => null,
            'created_at' => $now,
        ];

        return $this->db->table('auth_refresh_tokens')->insert($row) > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getActiveSessionByRefreshToken(string $refreshToken): ?array
    {
        $hash = $this->hashToken($refreshToken);
        $now = date('Y-m-d H:i:s');

        $sql = <<<'SQL'
SELECT
    rt.uuid AS refresh_uuid,
    rt.session_uuid,
    rt.user_uuid,
    rt.status AS refresh_status,
    rt.issued_at AS refresh_issued_at,
    rt.expires_at AS refresh_expires_at,
    rt.consumed_at AS refresh_consumed_at,
    s.provider,
    s.remember_me,
    s.created_at AS session_created_at,
    s.session_version,
    s.updated_at AS session_updated_at,
    s.status AS session_status
FROM auth_refresh_tokens rt
JOIN auth_sessions s ON s.uuid = rt.session_uuid
WHERE rt.token_hash = :token_hash
  AND rt.status = 'active'
  AND rt.expires_at > :now
  AND s.status = 'active'
LIMIT 1
SQL;

        $stmt = $this->db->getPDO()->prepare($sql);
        $stmt->execute([
            'token_hash' => $hash,
            'now' => $now,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Rotate an active refresh token in a single transaction.
     *
     * Returns session context when successful, null otherwise.
     *
     * @return array<string, mixed>|null
     */
    public function rotateActiveToken(
        string $oldRefreshToken,
        string $newRefreshToken,
        int $ttlSeconds,
        bool $strictReplay = true
    ): ?array {
        $pdo = $this->db->getPDO();
        $oldHash = $this->hashToken($oldRefreshToken);
        $newHash = $this->hashToken($newRefreshToken);
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + max(1, $ttlSeconds));

        try {
            $pdo->beginTransaction();

            $selectSql = <<<'SQL'
SELECT
    rt.uuid,
    rt.session_uuid,
    rt.user_uuid,
    rt.status AS refresh_status,
    rt.consumed_at AS refresh_consumed_at,
    rt.expires_at,
    s.provider,
    s.remember_me,
    s.created_at AS session_created_at,
    s.session_version,
    s.updated_at AS session_updated_at,
    s.status AS session_status
FROM auth_refresh_tokens rt
JOIN auth_sessions s ON s.uuid = rt.session_uuid
WHERE rt.token_hash = :token_hash
LIMIT 1
SQL;
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                $selectSql .= ' FOR UPDATE';
            }
            $selectStmt = $pdo->prepare($selectSql);
            $lockWaitStartedAt = microtime(true);
            $selectStmt->execute(['token_hash' => $oldHash]);
            $current = $selectStmt->fetch(PDO::FETCH_ASSOC);
            $lockWaitMs = (int) round((microtime(true) - $lockWaitStartedAt) * 1000);

            if ($current === false) {
                $pdo->rollBack();
                return null;
            }

            $sessionUuid = (string) $current['session_uuid'];
            $refreshStatus = (string) $current['refresh_status'];
            $sessionStatus = (string) $current['session_status'];
            $isExpired = isset($current['expires_at']) && strtotime((string) $current['expires_at']) < time();

            if ($refreshStatus !== 'active' || $sessionStatus !== 'active' || $isExpired) {
                if ($strictReplay && in_array($refreshStatus, ['consumed', 'revoked'], true)) {
                    $this->revokeSessionScopeTx($pdo, $sessionUuid);
                }
                $pdo->commit();
                return null;
            }

            $newUuid = Utils::generateNanoID();
            $insertSql = <<<'SQL'
INSERT INTO auth_refresh_tokens
    (uuid, session_uuid, user_uuid, token_hash, status,
     parent_uuid, replaced_by_uuid,
     issued_at, expires_at, consumed_at, created_at)
VALUES
    (:uuid, :session_uuid, :user_uuid, :token_hash, 'active',
     :parent_uuid, NULL,
     :issued_at, :expires_at, NULL, :created_at)
SQL;
            $insertStmt = $pdo->prepare($insertSql);
            $insertStmt->execute([
                'uuid' => $newUuid,
                'session_uuid' => $sessionUuid,
                'user_uuid' => (string) $current['user_uuid'],
                'token_hash' => $newHash,
                'parent_uuid' => (string) $current['uuid'],
                'issued_at' => $now,
                'expires_at' => $expiresAt,
                'created_at' => $now,
            ]);

            $consumeSql = <<<'SQL'
UPDATE auth_refresh_tokens
SET status = 'consumed',
    consumed_at = :consumed_at,
    replaced_by_uuid = :replaced_by_uuid
WHERE uuid = :uuid
SQL;
            $consumeStmt = $pdo->prepare($consumeSql);
            $consumeStmt->execute([
                'consumed_at' => $now,
                'replaced_by_uuid' => $newUuid,
                'uuid' => (string) $current['uuid'],
            ]);

            $versionSql = <<<'SQL'
UPDATE auth_sessions
SET session_version = COALESCE(session_version, 1) + 1,
    updated_at = :updated_at
WHERE uuid = :session_uuid
SQL;
            $versionStmt = $pdo->prepare($versionSql);
            $versionStmt->execute([
                'updated_at' => $now,
                'session_uuid' => $sessionUuid,
            ]);

            $versionFetch = $pdo->prepare(
                'SELECT session_version FROM auth_sessions WHERE uuid = :session_uuid LIMIT 1'
            );
            $versionFetch->execute(['session_uuid' => $sessionUuid]);
            $newVersion = (int) ($versionFetch->fetchColumn() ?: 1);

            $pdo->commit();

            $current['session_version'] = $newVersion;
            $current['new_refresh_uuid'] = $newUuid;
            $current['lock_wait_ms'] = $lockWaitMs;
            return $current;
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return null;
        }
    }

    public function revokeSessionScope(string $sessionUuid): void
    {
        $pdo = $this->db->getPDO();
        try {
            $pdo->beginTransaction();
            $this->revokeSessionScopeTx($pdo, $sessionUuid);
            $pdo->commit();
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private function revokeSessionScopeTx(PDO $pdo, string $sessionUuid): void
    {
        $sessionStmt = $pdo->prepare(
            "UPDATE auth_sessions SET status = 'revoked' WHERE uuid = :session_uuid AND status = 'active'"
        );
        $sessionStmt->execute(['session_uuid' => $sessionUuid]);

        $tokensStmt = $pdo->prepare(
            "UPDATE auth_refresh_tokens SET status = 'revoked' WHERE session_uuid = :session_uuid AND status = 'active'"
        );
        $tokensStmt->execute(['session_uuid' => $sessionUuid]);
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
