<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Auth;

use Glueful\Application;
use Glueful\Auth\{PasswordHasher, UserIdentity, UserProvider};
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Framework;
use Glueful\Repository\UserRepository;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;

/**
 * UserProvider adapts UserRepository to UserProviderInterface. Mirrors
 * ApiKeyAuthenticationTest's boot + inline-schema pattern: an in-memory SQLite app with the
 * shared BaseRepository connection pre-seeded so the users table is visible to every repo.
 */
final class UserProviderTest extends TestCase
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
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->appPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->appPath);
        }
        parent::tearDown();
    }

    private function seedUser(): string
    {
        // create() returns a STRING uuid (not an array) and does NOT hash the password —
        // store a real hash that PasswordHasher::verify() will match.
        return (new UserRepository())->create([
            'username' => 'amy',
            'email' => 'amy@x.test',
            'password' => (new PasswordHasher())->hash('secret-123'),
            'status' => 'active',
        ]);
    }

    public function test_find_and_verify(): void
    {
        $uuid = $this->seedUser();
        $provider = new UserProvider(new UserRepository());

        self::assertInstanceOf(UserIdentity::class, $provider->findByUuid($uuid));
        self::assertSame($uuid, $provider->findByLogin('amy@x.test')?->uuid());
        self::assertSame($uuid, $provider->findByLogin('amy')?->uuid());

        self::assertSame($uuid, $provider->verifyCredentials('amy@x.test', 'secret-123')?->uuid());
        self::assertNull($provider->verifyCredentials('amy@x.test', 'wrong'));
        self::assertNull($provider->findByUuid('does-not-exist'));
    }

    private function bootFramework(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-userprov-' . uniqid();
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        file_put_contents($cfg . '/app.php', "<?php\nreturn ['name'=>'T','env'=>'testing','debug'=>true];");
        file_put_contents(
            $cfg . '/database.php',
            "<?php\nreturn ['engine'=>'sqlite','sqlite'=>['primary'=>':memory:'],'pooling'=>['enabled'=>false]];"
        );
        file_put_contents($cfg . '/cache.php', "<?php\nreturn ['enabled'=>true,'default'=>'array','stores'=>['array'=>['driver'=>'array']]];");
        file_put_contents($cfg . '/security.php', "<?php\nreturn ['csrf'=>['enabled'=>false]];");
        file_put_contents($cfg . '/session.php', "<?php\nreturn ['jwt_key'=>'test'];");

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContainer()->get(ApplicationContext::class);
    }

    private function createSchemaInline(): void
    {
        $connection = $this->app->getContainer()->get('database');
        // Pre-seed BaseRepository's static shared connection so every UserRepository reuses the
        // same in-memory SQLite PDO (and sees this table).
        new UserRepository($connection, null, $this->context);

        $connection->getPDO()->exec('
            CREATE TABLE users (
                uuid VARCHAR(12) PRIMARY KEY,
                username VARCHAR(255),
                email VARCHAR(255),
                password VARCHAR(255),
                status VARCHAR(32) DEFAULT "active",
                created_at TIMESTAMP NULL
            )
        ');
    }
}
