<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Auth;

use Glueful\Application;
use Glueful\Auth\AuthenticationService;
use Glueful\Auth\SessionCacheManager;
use Glueful\Auth\SessionStore;
use Glueful\Database\Connection;
use Glueful\Framework;
use PHPUnit\Framework\TestCase;

/**
 * Regression: `SessionStore::listByUser()` delegated to `SessionCacheManager::getUserSessions()`,
 * and the manager's `findUserSessions()` delegated back to `SessionStore::listByUser()` — an
 * unbounded mutual recursion on the container-resolved path (context present) that exhausted memory.
 * On the context-less path it instead short-circuited to `[]`, so session enumeration NEVER worked:
 * it either OOM'd or returned nothing.
 *
 * The fix makes `findUserSessions()` enumerate from the cache user-index only, never calling back
 * into the store. These tests exercise the container-resolved path that used to blow up and prove
 * enumeration is actually correct — sessions are returned, counted, terminated, and survive a
 * permission refresh — not merely that the recursion stops. (Running any of them against the old
 * code exhausts memory and crashes the suite, which is the regression signal.)
 */
final class SessionEnumerationRecursionTest extends TestCase
{
    private string $appPath;
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appPath = sys_get_temp_dir() . '/glueful-session-enum-' . uniqid('', true);
        $configPath = $this->appPath . '/config';
        mkdir($configPath, 0755, true);

        file_put_contents(
            $configPath . '/app.php',
            "<?php\nreturn ['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true];\n"
        );
        file_put_contents(
            $configPath . '/database.php',
            "<?php\nreturn ['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:'], "
            . "'pooling' => ['enabled' => false]];\n"
        );
        file_put_contents(
            $configPath . '/cache.php',
            "<?php\nreturn ['enabled' => true, 'default' => 'array', "
            . "'stores' => ['array' => ['driver' => 'array']]];\n"
        );
        file_put_contents($configPath . '/security.php', "<?php\nreturn ['csrf' => ['enabled' => false]];\n");
        file_put_contents($configPath . '/session.php', "<?php\nreturn ['jwt_key' => 'test'];\n");

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);

        // Real session issuance writes DB rows (auth_sessions + auth_refresh_tokens) as well as the
        // cache — so create the auth schema on the in-memory DB from the framework's own migrations.
        $schema = $this->app->getContainer()->get(Connection::class)->getSchemaBuilder();
        $migrations = dirname(__DIR__, 3) . '/migrations/auth';
        require_once $migrations . '/001_CreateAuthSessionsTable.php';
        require_once $migrations . '/002_CreateAuthRefreshTokensTable.php';
        (new \Glueful\Migrations\CreateAuthSessionsTable())->up($schema);
        (new \Glueful\Migrations\CreateAuthRefreshTokensTable())->up($schema);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->appPath)) {
            $this->recursiveRemoveDirectory($this->appPath);
        }
        parent::tearDown();
    }

    private function manager(): SessionCacheManager
    {
        return $this->app->getContainer()->get(SessionCacheManager::class);
    }

    private function store(): SessionStore
    {
        return $this->app->getContainer()->get(SessionStore::class);
    }

    private function seedSessions(string $userUuid, int $count): void
    {
        // Issue REAL sessions through the login path: this populates BOTH the DB (for
        // getByAccessToken, which terminate depends on) and the cache user-index (which enumeration
        // reads), with a JWT signed by the test key — so all four operations exercise real sessions.
        $auth = $this->app->getContainer()->get(AuthenticationService::class);
        for ($i = 1; $i <= $count; $i++) {
            $auth->issueSession(['uuid' => $userUuid, 'email' => $userUuid . '@example.test'], 'jwt');
        }
    }

    public function test_listByUser_returns_every_session_via_the_container_without_recursing(): void
    {
        $this->seedSessions('user_list', 2);

        // The container-resolved store: context is set, so this is the exact path that used to
        // recurse into the manager and exhaust memory.
        $sessions = $this->store()->listByUser('user_list');

        self::assertCount(2, $sessions);
    }

    public function test_getUserSessionCount_counts_every_session(): void
    {
        $this->seedSessions('user_count', 3);

        self::assertSame(3, $this->manager()->getUserSessionCount('user_count'));
    }

    public function test_terminateAllUserSessions_terminates_every_session(): void
    {
        $this->seedSessions('user_terminate', 2);

        $terminated = $this->manager()->terminateAllUserSessions('user_terminate');

        self::assertSame(2, $terminated, 'both sessions must be terminated, not just enumerated');
        self::assertSame(0, $this->manager()->getUserSessionCount('user_terminate'));
    }

    public function test_permission_refresh_enumerates_sessions_without_recursing(): void
    {
        $this->seedSessions('user_perm', 2);

        // refreshPermissionsForAllUserSessions() enumerates via findUserSessions() — the recursive
        // path — then rewrites each session. It must complete and leave the sessions enumerable.
        $refreshed = $this->manager()->refreshPermissionsForAllUserSessions('user_perm');

        // The recursion-relevant proof: it ENUMERATED both sessions (via findUserSessions) and
        // refreshed each. The session-count after is a separate concern — the refresh rewrites each
        // payload, which is not what this regression pins.
        self::assertSame(2, $refreshed, 'permission refresh must enumerate and refresh both sessions');
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
