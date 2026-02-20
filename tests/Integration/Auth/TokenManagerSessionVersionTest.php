<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Auth;

use Glueful\Auth\JWTService;
use Glueful\Auth\TokenManager;
use Glueful\Database\Connection;
use Glueful\Http\RequestContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TokenManagerSessionVersionTest extends TestCase
{
    private string $dbPath;
    private Connection $connection;
    private TokenManager $tokenManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath = sys_get_temp_dir() . '/glueful-token-version-' . uniqid('', true) . '.sqlite';
        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->dbPath],
            'pooling' => ['enabled' => false],
        ]);
        $this->tokenManager = new TokenManager(null, $this->connection);

        $this->setJwtKeyForTests('test-jwt-key');

        $pdo = $this->connection->getPDO();
        $pdo->exec(
            'CREATE TABLE auth_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT UNIQUE NOT NULL,
                user_uuid TEXT NOT NULL,
                access_token TEXT,
                session_version INTEGER DEFAULT 1,
                status TEXT DEFAULT "active",
                created_at TEXT,
                updated_at TEXT
            )'
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
        parent::tearDown();
    }

    public function testValidateAccessTokenRejectsOutdatedSessionVersion(): void
    {
        $token = JWTService::generate([
            'uuid' => 'user_002',
            'sid' => 'sess_002',
            'ver' => 1,
        ], 3600);

        $stmt = $this->connection->getPDO()->prepare(
            'INSERT INTO auth_sessions (uuid, user_uuid, access_token, session_version, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, datetime("now"), datetime("now"))'
        );
        $stmt->execute(['sess_002', 'user_002', $token, 1, 'active']);

        $requestContext = RequestContext::fromGlobals();

        $this->assertTrue($this->tokenManager->validateAccessToken($token, null, $requestContext));

        $this->connection->getPDO()
            ->exec("UPDATE auth_sessions SET session_version = 2 WHERE uuid = 'sess_002'");

        $this->assertFalse($this->tokenManager->validateAccessToken($token, null, $requestContext));
    }

    private function setJwtKeyForTests(string $key): void
    {
        $ref = new ReflectionClass(JWTService::class);
        $property = $ref->getProperty('key');
        $property->setValue(null, $key);
    }
}
