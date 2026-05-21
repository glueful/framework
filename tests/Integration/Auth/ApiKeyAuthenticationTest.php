<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Auth;

use Glueful\Application;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Auth\ApiKey\Exceptions\ApiKeyExpiredException;
use Glueful\Auth\ApiKey\Exceptions\InvalidApiKeyException;
use Glueful\Auth\ApiKeyAuthenticationProvider;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Service-level + provider-level integration tests for the hardened API key
 * system. The migration lives in api-skeleton (`009_CreateApiKeysTable.php`)
 * so the framework test creates the `api_keys` table inline via PDO to stay
 * self-contained — running the migration would couple test environments
 * across repos.
 */
class ApiKeyAuthenticationTest extends TestCase
{
    private string $appPath;
    private Application $app;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        RouteManifest::reset();
        $this->bootFramework();
        $this->createSchemaInline();
    }

    protected function tearDown(): void
    {
        if (isset($this->appPath) && is_dir($this->appPath)) {
            $this->recursiveRemoveDirectory($this->appPath);
        }
        parent::tearDown();
    }

    // ── Service-level ──

    public function testCreateThenVerifyHappyPath(): void
    {
        $result = ApiKeyService::create($this->context, [
            'user_id' => 'u-abc12345abcd',
            'name'    => 'Test Key',
        ]);
        $verified = ApiKeyService::verify($this->context, $result['plain'], '203.0.113.5');
        $this->assertSame($result['key']->uuid, $verified->uuid);
    }

    public function testVerifyThrowsInvalidOnWrongHash(): void
    {
        ApiKeyService::create($this->context, ['user_id' => 'u-abc12345abcd', 'name' => 'X']);
        $this->expectException(InvalidApiKeyException::class);
        ApiKeyService::verify($this->context, 'gf_test_zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz', '1.2.3.4');
    }

    public function testVerifyThrowsExpiredForPastExpiry(): void
    {
        $result = ApiKeyService::create($this->context, [
            'user_id'    => 'u-abc12345abcd',
            'name'       => 'Past',
            'expires_at' => date('Y-m-d H:i:s', time() - 60),
        ]);
        $this->expectException(ApiKeyExpiredException::class);
        ApiKeyService::verify($this->context, $result['plain'], '1.2.3.4');
    }

    public function testVerifyEnforcesIpAllowlist(): void
    {
        $result = ApiKeyService::create($this->context, [
            'user_id'     => 'u-abc12345abcd',
            'name'        => 'IP-restricted',
            'allowed_ips' => ['10.0.0.0/8'],
        ]);
        $this->expectException(InvalidApiKeyException::class);
        ApiKeyService::verify($this->context, $result['plain'], '203.0.113.5');
    }

    public function testRotateProducesNewKeyAndKeepsOldValidDuringGrace(): void
    {
        $original = ApiKeyService::create($this->context, ['user_id' => 'u-abc12345abcd', 'name' => 'O']);
        $rotation = ApiKeyService::rotate($this->context, $original['key'], graceHours: 24);

        $this->assertNotNull(ApiKeyService::verify($this->context, $rotation['new_plain'], '1.2.3.4'));
        $this->assertNotNull(ApiKeyService::verify($this->context, $original['plain'], '1.2.3.4'));
    }

    public function testRevokedKeyFailsVerify(): void
    {
        $result = ApiKeyService::create($this->context, ['user_id' => 'u-abc12345abcd', 'name' => 'Doomed']);
        ApiKeyService::revoke($this->context, $result['key']);

        $this->expectException(InvalidApiKeyException::class);
        ApiKeyService::verify($this->context, $result['plain'], '1.2.3.4');
    }

    // ── Provider-level ──

    public function testProviderAuthenticatesAndPopulatesScopes(): void
    {
        $this->ensureUserRow('u-abc12345abcd');

        $result = ApiKeyService::create($this->context, [
            'user_id' => 'u-abc12345abcd',
            'name'    => 'Provider Test',
            'scopes'  => ['read:posts'],
        ]);

        $provider = new ApiKeyAuthenticationProvider($this->context);
        $request = Request::create('/x', 'GET');
        $request->headers->set('X-API-Key', $result['plain']);

        $userData = $provider->authenticate($request);
        $this->assertNotNull($userData, 'Provider returned null. lastError: ' . ($provider->getError() ?? '(none)'));
        $this->assertSame('api_key', $request->attributes->get('auth_method'));
        $this->assertSame(['read:posts'], $request->attributes->get('api_key_scopes'));
    }

    public function testProviderReturnsNullForRevokedKey(): void
    {
        $this->ensureUserRow('u-abc12345abcd');

        $result = ApiKeyService::create($this->context, ['user_id' => 'u-abc12345abcd', 'name' => 'Doomed']);
        ApiKeyService::revoke($this->context, $result['key']);

        $provider = new ApiKeyAuthenticationProvider($this->context);
        $request = Request::create('/x', 'GET');
        $request->headers->set('X-API-Key', $result['plain']);

        $this->assertNull($provider->authenticate($request));
    }

    public function testProviderReturnsNullForUnknownKey(): void
    {
        $provider = new ApiKeyAuthenticationProvider($this->context);
        $request = Request::create('/x', 'GET');
        $request->headers->set('X-API-Key', 'gf_test_does_not_exist_at_all_in_table_xxxx');

        $this->assertNull($provider->authenticate($request));
    }

    // ── Harness ──

    private function bootFramework(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-apikey-' . uniqid();
        $configPath = $this->appPath . '/config';
        mkdir($configPath, 0755, true);

        file_put_contents(
            $configPath . '/app.php',
            "<?php\nreturn ['name'=>'T','version_full'=>'1.0.0','env'=>'testing','debug'=>true];"
        );
        file_put_contents(
            $configPath . '/database.php',
            "<?php\nreturn ['engine'=>'sqlite','sqlite'=>['primary'=>':memory:'],'pooling'=>['enabled'=>false]];"
        );
        file_put_contents(
            $configPath . '/cache.php',
            "<?php\nreturn ['enabled'=>true,'default'=>'array','stores'=>['array'=>['driver'=>'array']]];"
        );
        file_put_contents($configPath . '/security.php', "<?php\nreturn ['csrf'=>['enabled'=>false]];");
        file_put_contents($configPath . '/session.php', "<?php\nreturn ['jwt_key'=>'test'];");

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContainer()->get(ApplicationContext::class);
    }

    private function createSchemaInline(): void
    {
        $connection = $this->app->getContainer()->get('database');

        // Pre-seed BaseRepository's static $sharedConnection so subsequent
        // UserRepository instances reuse the SAME Connection (and therefore
        // the same in-memory SQLite PDO). Without this, BaseRepository
        // resolves a fresh Connection via Connection::fromContext(), which
        // opens a separate :memory: database that doesn't see our tables.
        new \Glueful\Repository\UserRepository($connection, null, $this->context);

        $pdo = $connection->getPDO();
        $pdo->exec('
            CREATE TABLE api_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid VARCHAR(12) NOT NULL UNIQUE,
                user_id VARCHAR(12) NOT NULL,
                name VARCHAR(255) NOT NULL,
                key_prefix VARCHAR(24) NOT NULL,
                key_hash VARCHAR(64) NOT NULL UNIQUE,
                scopes TEXT NULL,
                allowed_ips TEXT NULL,
                expires_at TIMESTAMP NULL,
                rotated_from_id INTEGER NULL,
                revoked_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $pdo->exec('CREATE INDEX idx_api_keys_user_id ON api_keys(user_id)');
        $pdo->exec('CREATE INDEX idx_api_keys_key_prefix ON api_keys(key_prefix)');

        $pdo->exec('
            CREATE TABLE users (
                uuid VARCHAR(12) PRIMARY KEY,
                username VARCHAR(255),
                email VARCHAR(255)
            )
        ');
    }

    private function ensureUserRow(string $uuid): void
    {
        $pdo = $this->app->getContainer()->get('database')->getPDO();
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO users (uuid, username, email) VALUES (?, ?, ?)');
        $stmt->execute([$uuid, 'test_user_' . $uuid, $uuid . '@example.com']);
    }

    private function recursiveRemoveDirectory(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursiveRemoveDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
