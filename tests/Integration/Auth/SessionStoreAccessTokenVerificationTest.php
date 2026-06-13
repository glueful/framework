<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Auth;

use Glueful\Auth\JWTService;
use Glueful\Auth\SessionStore;
use Glueful\Database\Connection;
use Glueful\Http\RequestContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression: SessionStore::getByAccessToken() resolved the session from UNVERIFIED
 * token claims. Its DB fallback read sid/ver via JWTService::getPayloadWithoutValidation()
 * (a plain base64 decode -- no signature check), so a token with a forged signature but
 * valid-looking claims resolved a real session. Consumers that treat the resolved
 * session as identity (AdminPermissionMiddleware / PermissionManager's
 * getUserUuidFromToken()) would then trust an attacker-chosen user_uuid.
 *
 * getByAccessToken() must only trust claims from JWTService::decode() -- full signature
 * and expiry verification -- and fail closed when verification fails.
 */
final class SessionStoreAccessTokenVerificationTest extends TestCase
{
    private string $dbPath;
    private Connection $connection;
    private SessionStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath = sys_get_temp_dir() . '/glueful-session-verify-' . uniqid('', true) . '.sqlite';
        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->dbPath],
            'pooling' => ['enabled' => false],
        ]);

        $this->setJwtKeyForTests('server-secret-key');

        $this->connection->getPDO()->exec(
            'CREATE TABLE auth_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT UNIQUE NOT NULL,
                user_uuid TEXT NOT NULL,
                access_token TEXT,
                session_version INTEGER DEFAULT 1,
                status TEXT DEFAULT "active",
                expires_at TEXT,
                created_at TEXT,
                updated_at TEXT
            )'
        );

        $stmt = $this->connection->getPDO()->prepare(
            'INSERT INTO auth_sessions
                (uuid, user_uuid, session_version, status, expires_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, datetime("now"), datetime("now"))'
        );
        $stmt->execute(['sess_001', 'user_001', 1, 'active', date('Y-m-d H:i:s', time() + 3600)]);

        $this->store = new SessionStore(
            null,
            $this->connection,
            RequestContext::fromGlobals(),
            false
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
        parent::tearDown();
    }

    public function test_token_with_forged_signature_cannot_resolve_a_session(): void
    {
        $valid = JWTService::generate(['uuid' => 'user_001', 'sid' => 'sess_001', 'ver' => 1], 3600);

        // Keep the header and claims (sid/ver point at a real active session) but replace
        // the signature -- exactly what an attacker who knows/guesses a session id can craft.
        $parts = explode('.', $valid);
        $parts[2] = rtrim(strtr(base64_encode('forged-signature'), '+/', '-_'), '=');
        $forged = implode('.', $parts);

        self::assertNull(
            $this->store->getByAccessToken($forged),
            'a session must never be resolved from claims whose signature does not verify'
        );
    }

    public function test_properly_signed_token_resolves_the_session(): void
    {
        $token = JWTService::generate(['uuid' => 'user_001', 'sid' => 'sess_001', 'ver' => 1], 3600);

        $session = $this->store->getByAccessToken($token);

        self::assertNotNull($session);
        self::assertSame('sess_001', $session['uuid']);
        self::assertSame('user_001', $session['user_uuid']);
    }

    public function test_expired_signed_token_cannot_resolve_a_session(): void
    {
        $token = JWTService::generate(['uuid' => 'user_001', 'sid' => 'sess_001', 'ver' => 1], -100);

        self::assertNull(
            $this->store->getByAccessToken($token),
            'an expired token must fail verification even when the session row is still active'
        );
    }

    private function setJwtKeyForTests(string $key): void
    {
        $ref = new ReflectionClass(JWTService::class);
        $property = $ref->getProperty('key');
        $property->setValue(null, $key);
    }
}
