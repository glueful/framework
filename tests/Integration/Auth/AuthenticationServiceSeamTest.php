<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Auth;

use Glueful\Application;
use Glueful\Auth\AuthenticationService;
use Glueful\Auth\PasswordHasher;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Framework;
use Glueful\Repository\UserRepository;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard: AuthenticationService::verifyCredentials() now routes credential
 * verification through UserProviderInterface, but observable behaviour is unchanged — a valid
 * user authenticates, a wrong password does not.
 */
final class AuthenticationServiceSeamTest extends TestCase
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

    public function test_verify_credentials_still_authenticates_a_valid_user(): void
    {
        (new UserRepository())->create([
            'username' => 'amy',
            'email' => 'amy@x.test',
            'password' => (new PasswordHasher())->hash('secret-123'),
            'status' => 'active',
        ]);

        /** @var AuthenticationService $svc */
        $svc = $this->app->getContainer()->get(AuthenticationService::class);

        $userData = $svc->verifyCredentials(['email' => 'amy@x.test', 'password' => 'secret-123']);
        self::assertNotNull($userData);
        self::assertSame('amy@x.test', $userData['email'] ?? null);

        self::assertNull($svc->verifyCredentials(['email' => 'amy@x.test', 'password' => 'wrong']));
    }

    private function bootFramework(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-authseam-' . uniqid();
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
        new UserRepository($connection, null, $this->context); // pre-seed shared connection

        $pdo = $connection->getPDO();
        $pdo->exec('
            CREATE TABLE users (
                uuid VARCHAR(12) PRIMARY KEY,
                username VARCHAR(255),
                email VARCHAR(255),
                password VARCHAR(255),
                status VARCHAR(32) DEFAULT "active",
                created_at TIMESTAMP NULL
            )
        ');
        $pdo->exec('CREATE TABLE profiles (user_uuid VARCHAR(12), first_name VARCHAR(255) NULL)');
    }
}
