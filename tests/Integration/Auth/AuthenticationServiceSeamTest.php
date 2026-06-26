<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Auth;

use Glueful\Application;
use Glueful\Auth\AuthenticationService;
use Glueful\Auth\IdentityResolver;
use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\Auth\AuthenticationFailedEvent;
use Glueful\Events\EventService;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use Glueful\Tests\Support\Auth\InMemoryUserProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard: AuthenticationService::verifyCredentials() routes credential verification
 * through UserProviderInterface, and observable behaviour is unchanged — a valid user
 * authenticates, a wrong password does not. Uses an in-memory provider so the framework suite
 * stays decoupled from any concrete user store (the real provider is covered by glueful/users).
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
        // Core defaults to NullUserProvider; inject an in-memory provider to exercise the seam.
        // (End-to-end with a real provider bound via DI is covered by glueful/users' own suite.)
        $provider = (new InMemoryUserProvider())->add(
            new UserIdentity('u-amy00000001', email: 'amy@x.test', username: 'amy', status: 'active'),
            'secret-123',
            'amy@x.test',
        );
        $svc = new AuthenticationService(
            context: $this->context,
            userProvider: $provider,
            identityResolver: new IdentityResolver([]),
        );

        $userData = $svc->verifyCredentials(['email' => 'amy@x.test', 'password' => 'secret-123']);
        self::assertNotNull($userData);
        self::assertSame('amy@x.test', $userData['email'] ?? null);

        self::assertNull($svc->verifyCredentials(['email' => 'amy@x.test', 'password' => 'wrong']));
    }

    public function test_failed_login_dispatches_authentication_failed_event(): void
    {
        $provider = (new InMemoryUserProvider())->add(
            new UserIdentity('u-amy00000001', email: 'amy@x.test', username: 'amy', status: 'active'),
            'secret-123',
            'amy@x.test',
        );

        /** @var list<AuthenticationFailedEvent> $captured */
        $captured = [];
        $events = $this->app->getContainer()->get(EventService::class);
        $events->addListener(
            AuthenticationFailedEvent::class,
            function (AuthenticationFailedEvent $e) use (&$captured): void {
                $captured[] = $e;
            }
        );

        $svc = new AuthenticationService(
            context: $this->context,
            userProvider: $provider,
            identityResolver: new IdentityResolver([]),
        );

        // A valid-format but wrong password reaches the credential check (a short one would be
        // rejected earlier by the password-format guard) -> rejected AND one
        // AuthenticationFailedEvent (invalid_credentials).
        self::assertNull($svc->verifyCredentials(['email' => 'amy@x.test', 'password' => 'wrongpass-1']));
        self::assertCount(1, $captured);
        self::assertSame('amy@x.test', $captured[0]->getUsername());
        self::assertSame('invalid_credentials', $captured[0]->getReason());

        // A successful login emits nothing further.
        self::assertNotNull($svc->verifyCredentials(['email' => 'amy@x.test', 'password' => 'secret-123']));
        self::assertCount(1, $captured);
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
}
