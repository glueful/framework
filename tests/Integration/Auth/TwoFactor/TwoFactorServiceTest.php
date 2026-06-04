<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Auth\TwoFactor;

use Glueful\Auth\JWTService;
use Glueful\Auth\TokenManager;
use Glueful\Auth\TwoFactor\ChallengeTokenIssuer;
use Glueful\Auth\TwoFactor\Exceptions\InvalidChallengeTokenException;
use Glueful\Auth\TwoFactor\Exceptions\InvalidTwoFactorCodeException;
use Glueful\Auth\TwoFactor\Exceptions\TwoFactorNotEnabledException;
use Glueful\Auth\TwoFactor\JtiBlocklist;
use Glueful\Extensions\Users\TwoFactor\TwoFactorService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Database\Connection;
use Glueful\Notifications\Services\NotificationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TwoFactorServiceTest extends TestCase
{
    private string $dbPath;
    private Connection $connection;
    private ArrayCacheDriver $cache;
    private ApplicationContext $context;
    private JtiBlocklist $blocklist;
    /** @var list<array{type:string,subject:string,data:array<string,mixed>}> */
    private array $sentNotifications = [];
    /** @var list<array{user:array<string,mixed>,provider:?string,sid:string}> */
    private array $tokenCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        // JWTService static key (used by ChallengeTokenIssuer + the mock session token).
        (new ReflectionClass(JWTService::class))->getProperty('key')->setValue(null, 'test-2fa-service-key');

        $this->dbPath = sys_get_temp_dir() . '/glueful-2fa-svc-' . uniqid('', true) . '.sqlite';
        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->dbPath],
            'pooling' => ['enabled' => false],
        ]);
        $this->connection->getPDO()->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT UNIQUE NOT NULL,
                email TEXT,
                status TEXT DEFAULT "active",
                password TEXT,
                two_factor_enabled INTEGER NOT NULL DEFAULT 0
            )'
        );

        $this->cache = new ArrayCacheDriver();
        $this->context = new ApplicationContext(sys_get_temp_dir() . '/glueful-2fa-ctx-' . uniqid('', true));
        $this->blocklist = new JtiBlocklist($this->cache);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
        parent::tearDown();
    }

    private function seedUser(string $uuid, string $email, bool $twoFactor, string $status = 'active'): void
    {
        $stmt = $this->connection->getPDO()->prepare(
            'INSERT INTO users (uuid, email, status, password, two_factor_enabled) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$uuid, $email, $status, password_hash('secret', PASSWORD_BCRYPT), $twoFactor ? 1 : 0]);
    }

    private function makeService(bool $masterEnabled = true): TwoFactorService
    {
        $issuer = new ChallengeTokenIssuer($this->blocklist, 300);

        $notifications = $this->createMock(NotificationService::class);
        $notifications->method('send')->willReturnCallback(
            function (string $type, $notifiable, string $subject, array $data = [], array $options = []): array {
                $this->sentNotifications[] = ['type' => $type, 'subject' => $subject, 'data' => $data];
                return ['status' => 'sent'];
            }
        );

        $tokenManager = $this->createMock(TokenManager::class);
        $tokenManager->method('createUserSession')->willReturnCallback(
            function (array $user, ?string $provider = null): array {
                $sid = 'sid-' . (count($this->tokenCalls) + 1);
                $this->tokenCalls[] = ['user' => $user, 'provider' => $provider, 'sid' => $sid];
                $access = JWTService::generate(['sub' => $user['uuid'] ?? '', 'sid' => $sid, 'ver' => 1], 3600);
                return [
                    'access_token' => $access,
                    'refresh_token' => 'refresh-' . $sid,
                    'expires_in' => 3600,
                    'token_type' => 'Bearer',
                    'user' => $user,
                ];
            }
        );

        return new TwoFactorService(
            $this->context,
            $this->connection,
            $this->cache,
            $notifications,
            $issuer,
            $this->blocklist,
            $tokenManager,
            6,
            300,
            300,
            'two-factor-pin',
            $masterEnabled
        );
    }

    /** Pull the most recently dispatched PIN out of the captured notifications. */
    private function lastPin(): string
    {
        $last = end($this->sentNotifications);
        self::assertIsArray($last);
        return (string) $last['data']['pin'];
    }

    public function testIsEnabledReflectsColumn(): void
    {
        $svc = $this->makeService();
        $this->seedUser('u-on', 'on@example.com', true);
        $this->seedUser('u-off', 'off@example.com', false);

        $this->assertTrue($svc->isEnabled('u-on'));
        $this->assertFalse($svc->isEnabled('u-off'));
        $this->assertFalse($svc->isEnabled('u-missing'));
    }

    public function testMasterSwitchOffShortCircuits(): void
    {
        $svc = $this->makeService(masterEnabled: false);
        $this->seedUser('u-on', 'on@example.com', true);

        $this->assertFalse($svc->isEnabled('u-on'));
    }

    public function testBeginEnableIssuesChallengeAndDispatchesPin(): void
    {
        $svc = $this->makeService();
        $result = $svc->beginEnable('u-1', 'user@example.com');

        $this->assertNotEmpty($result['token']);
        $this->assertGreaterThan(0, $result['expires_in']);
        $this->assertSame('u***@example.com', $result['delivered_to']);
        $this->assertCount(1, $this->sentNotifications);
        $this->assertSame('two_factor_pin', $this->sentNotifications[0]['type']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->lastPin());
    }

    public function testBeginLoginProjectsToAllowlistAndDropsPassword(): void
    {
        $svc = $this->makeService();
        $rawRow = [
            'uuid' => 'u-2',
            'email' => 'user@example.com',
            'username' => 'bob',
            'status' => 'active',
            'password' => 'super-secret-hash',
            'created_at' => '2026-01-01',
            'last_login_at' => '2026-05-01',
            'internal_flag' => true,
        ];
        $begin = $svc->beginLogin($rawRow, 'jwt');

        // The PIN cache key is 2fa:pin:{jti}; the jti is the challenge token's jti.
        $claims = JWTService::decode($begin['token']);
        $this->assertIsArray($claims);
        $jti = (string) $claims['jti'];
        $entry = $this->cache->get("2fa:pin:{$jti}");
        $this->assertIsArray($entry);
        $userKeys = array_keys($entry['user']);
        sort($userKeys);
        $this->assertSame(['email', 'status', 'username', 'uuid'], $userKeys);
        $this->assertArrayNotHasKey('password', $entry['user']);
    }

    public function testBeginLoginRequiresUuidAndEmail(): void
    {
        $svc = $this->makeService();
        $this->expectException(\InvalidArgumentException::class);
        $svc->beginLogin(['uuid' => 'u-3'], 'jwt');
    }

    public function testVerifyEnableSetsColumn(): void
    {
        $svc = $this->makeService();
        $this->seedUser('u-4', 'user@example.com', false);

        $begin = $svc->beginEnable('u-4', 'user@example.com');
        $result = $svc->verify($begin['token'], $this->lastPin());

        $this->assertSame('enabled', $result['purpose']);
        $this->assertTrue($svc->isEnabled('u-4'));
    }

    public function testVerifyLoginCreatesSessionAndWritesFreshnessMarker(): void
    {
        $svc = $this->makeService();
        $this->seedUser('u-5', 'user@example.com', true);

        $begin = $svc->beginLogin(['uuid' => 'u-5', 'email' => 'user@example.com', 'status' => 'active'], 'jwt');
        $result = $svc->verify($begin['token'], $this->lastPin());

        $this->assertSame('login', $result['purpose']);
        $this->assertArrayHasKey('session', $result);
        $this->assertNotEmpty($result['session']['access_token']);
        $sid = $result['sid'];
        $this->assertTrue($svc->hasFreshVerification($sid));
        // Provider was carried through to createUserSession.
        $this->assertSame('jwt', $this->tokenCalls[0]['provider']);
    }

    public function testProviderPreferenceFlowsThroughToSession(): void
    {
        $svc = $this->makeService();
        $this->seedUser('u-ldap', 'user@example.com', true);

        $begin = $svc->beginLogin(['uuid' => 'u-ldap', 'email' => 'user@example.com', 'status' => 'active'], 'ldap');
        $svc->verify($begin['token'], $this->lastPin());

        $this->assertSame('ldap', $this->tokenCalls[0]['provider']);
    }

    public function testWrongPinThrowsAndKeepsPinForRetry(): void
    {
        $svc = $this->makeService();
        $this->seedUser('u-6', 'user@example.com', true);
        $begin = $svc->beginLogin(['uuid' => 'u-6', 'email' => 'user@example.com', 'status' => 'active'], 'jwt');
        $correct = $this->lastPin();

        try {
            $svc->verify($begin['token'], $correct === '000000' ? '111111' : '000000');
            $this->fail('Expected InvalidTwoFactorCodeException');
        } catch (InvalidTwoFactorCodeException) {
            // expected
        }

        // PIN entry still present → retry with correct PIN succeeds.
        $result = $svc->verify($begin['token'], $correct);
        $this->assertSame('login', $result['purpose']);
    }

    public function testReplayOfConsumedChallengeIsRejected(): void
    {
        $svc = $this->makeService();
        $this->seedUser('u-7', 'user@example.com', false);
        $begin = $svc->beginEnable('u-7', 'user@example.com');

        $svc->verify($begin['token'], $this->lastPin()); // consumes jti

        $this->expectException(InvalidChallengeTokenException::class);
        $svc->verify($begin['token'], $this->lastPin());
    }

    public function testRevalidationUserDeletedDuringWindow(): void
    {
        $svc = $this->makeService();
        $this->seedUser('u-8', 'user@example.com', true);
        $begin = $svc->beginLogin(['uuid' => 'u-8', 'email' => 'user@example.com', 'status' => 'active'], 'jwt');

        $this->connection->getPDO()->exec("DELETE FROM users WHERE uuid = 'u-8'");

        try {
            $svc->verify($begin['token'], $this->lastPin());
            $this->fail('Expected InvalidTwoFactorCodeException');
        } catch (InvalidTwoFactorCodeException) {
            // expected
        }
        $this->assertSame([], $this->tokenCalls, 'No session should be created for a deleted user');
    }

    public function testRevalidationStatusDisallowedDuringWindow(): void
    {
        $svc = $this->makeService();
        $this->seedUser('u-9', 'user@example.com', true);
        $begin = $svc->beginLogin(['uuid' => 'u-9', 'email' => 'user@example.com', 'status' => 'active'], 'jwt');

        $this->connection->getPDO()->exec("UPDATE users SET status = 'suspended' WHERE uuid = 'u-9'");

        $this->expectException(InvalidTwoFactorCodeException::class);
        $svc->verify($begin['token'], $this->lastPin());
    }

    public function testRevalidationTwoFactorDisabledDuringWindow(): void
    {
        $svc = $this->makeService();
        $this->seedUser('u-10', 'user@example.com', true);
        $begin = $svc->beginLogin(['uuid' => 'u-10', 'email' => 'user@example.com', 'status' => 'active'], 'jwt');

        $this->connection->getPDO()->exec("UPDATE users SET two_factor_enabled = 0 WHERE uuid = 'u-10'");

        $this->expectException(TwoFactorNotEnabledException::class);
        $svc->verify($begin['token'], $this->lastPin());
    }

    public function testFreshnessIsSessionScoped(): void
    {
        $svc = $this->makeService();
        $this->seedUser('u-11', 'user@example.com', true);

        $begin = $svc->beginLogin(['uuid' => 'u-11', 'email' => 'user@example.com', 'status' => 'active'], 'jwt');
        $result = $svc->verify($begin['token'], $this->lastPin());
        $sidA = $result['sid'];

        $this->assertTrue($svc->hasFreshVerification($sidA));
        $this->assertFalse($svc->hasFreshVerification('sid-other'));
    }

    public function testDisableClearsColumnAndCallingSessionMarker(): void
    {
        $svc = $this->makeService();
        $this->seedUser('u-12', 'user@example.com', true);

        $begin = $svc->beginLogin(['uuid' => 'u-12', 'email' => 'user@example.com', 'status' => 'active'], 'jwt');
        $result = $svc->verify($begin['token'], $this->lastPin());
        $sid = $result['sid'];
        $this->assertTrue($svc->hasFreshVerification($sid));

        $svc->disable('u-12', $sid);

        $this->assertFalse($svc->isEnabled('u-12'));
        $this->assertFalse($svc->hasFreshVerification($sid));
    }
}
