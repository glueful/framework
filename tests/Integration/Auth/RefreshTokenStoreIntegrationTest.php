<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Auth;

use Glueful\Auth\RefreshTokenStore;
use Glueful\Database\Connection;
use PHPUnit\Framework\TestCase;

final class RefreshTokenStoreIntegrationTest extends TestCase
{
    private string $dbPath;
    private Connection $connection;
    private RefreshTokenStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath = sys_get_temp_dir() . '/glueful-refresh-store-' . uniqid('', true) . '.sqlite';
        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->dbPath],
            'pooling' => ['enabled' => false],
        ]);
        $this->store = new RefreshTokenStore($this->connection);

        $pdo = $this->connection->getPDO();
        $pdo->exec(
            'CREATE TABLE auth_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT UNIQUE NOT NULL,
                user_uuid TEXT NOT NULL,
                access_token TEXT,
                session_version INTEGER DEFAULT 1,
                provider TEXT DEFAULT "jwt",
                remember_me INTEGER DEFAULT 0,
                status TEXT DEFAULT "active",
                created_at TEXT,
                updated_at TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE auth_refresh_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT UNIQUE NOT NULL,
                session_uuid TEXT NOT NULL,
                user_uuid TEXT NOT NULL,
                token_hash TEXT UNIQUE NOT NULL,
                status TEXT DEFAULT "active",
                parent_uuid TEXT,
                replaced_by_uuid TEXT,
                issued_at TEXT,
                expires_at TEXT,
                consumed_at TEXT,
                created_at TEXT
            )'
        );

        $stmt = $pdo->prepare(
            'INSERT INTO auth_sessions (uuid, user_uuid, access_token, session_version, provider, remember_me, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, datetime("now"), datetime("now"))'
        );
        $stmt->execute(['sess_001', 'user_001', 'at_001', 1, 'jwt', 0, 'active']);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
        parent::tearDown();
    }

    public function testRotateRefreshTokenSuccess(): void
    {
        $old = 'refresh_old_token';
        $new = 'refresh_new_token';

        $this->assertTrue($this->store->issue('sess_001', 'user_001', $old, 3600));

        $rotated = $this->store->rotateActiveToken($old, $new, 3600, true);
        $this->assertIsArray($rotated);
        $this->assertSame(2, (int) ($rotated['session_version'] ?? 0));

        $active = $this->store->getActiveSessionByRefreshToken($new);
        $this->assertIsArray($active);
        $this->assertSame('sess_001', (string) $active['session_uuid']);
    }

    public function testReplayRevokesSessionScope(): void
    {
        $old = 'refresh_old_token_replay';
        $new = 'refresh_new_token_replay';

        $this->assertTrue($this->store->issue('sess_001', 'user_001', $old, 3600));
        $this->assertIsArray($this->store->rotateActiveToken($old, $new, 3600, true));

        // Re-using old token should trigger strict replay revoke behavior.
        $this->assertNull($this->store->rotateActiveToken($old, 'another_token', 3600, true));

        $sessionStatus = (string) $this->connection->getPDO()
            ->query("SELECT status FROM auth_sessions WHERE uuid = 'sess_001' LIMIT 1")
            ->fetchColumn();
        $this->assertSame('revoked', $sessionStatus);

        $activeTokens = (int) $this->connection->getPDO()
            ->query("SELECT COUNT(*) FROM auth_refresh_tokens WHERE session_uuid = 'sess_001' AND status = 'active'")
            ->fetchColumn();
        $this->assertSame(0, $activeTokens);
    }
}
