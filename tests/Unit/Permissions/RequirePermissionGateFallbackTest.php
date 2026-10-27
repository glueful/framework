<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions;

use Glueful\Auth\UserIdentity;
use Glueful\Controllers\Traits\AuthorizationTrait;
use Glueful\Permissions\Context;
use Glueful\Permissions\Exceptions\UnauthorizedException;
use Glueful\Permissions\Gate;
use Glueful\Permissions\PermissionManager;
use Glueful\Permissions\Vote;
use Glueful\Permissions\VoterInterface;
use PHPUnit\Framework\TestCase;

/**
 * requirePermission() in a Gate-only app (no permission provider installed): the check must be
 * decided by PermissionManager's Gate fallback instead of refusing with "Permission system
 * unavailable" — while staying fail-closed for anonymous users and Gate denials.
 */
final class RequirePermissionGateFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        PermissionManager::getInstance()->clearProvider();
    }

    protected function tearDown(): void
    {
        PermissionManager::getInstance()->clearProvider();
    }

    public function test_gate_grant_allows_when_no_provider_is_installed(): void
    {
        $controller = new RequirePermissionHarness(
            new UserIdentity('u1', ['ops']),
            $this->managerWithRoleGrant('ops', 'system.metrics.view')
        );

        $controller->check('system.metrics.view', 'metrics:api');

        self::assertSame(
            [['u1', 'system.metrics.view', 'metrics:api', ['ops']]],
            $controller->managerCalls()
        );
    }

    public function test_gate_denial_throws_forbidden(): void
    {
        $controller = new RequirePermissionHarness(
            new UserIdentity('u1', ['viewer']),
            $this->managerWithRoleGrant('ops', 'system.metrics.view')
        );

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('You do not have permission to system.metrics.view on metrics:api');
        $controller->check('system.metrics.view', 'metrics:api');
    }

    public function test_unauthenticated_is_refused_before_any_gate_check(): void
    {
        $controller = new RequirePermissionHarness(null, $this->managerWithRoleGrant('ops', 'x'));

        try {
            $controller->check('x');
            self::fail('Expected UnauthorizedException');
        } catch (UnauthorizedException $e) {
            self::assertSame('Authentication required', $e->getMessage());
        }
        self::assertSame([], $controller->managerCalls());
    }

    private function managerWithRoleGrant(string $role, string $permission): PermissionManager
    {
        $gate = new Gate();
        $gate->registerVoter(new class ($role, $permission) implements VoterInterface {
            public function __construct(private string $role, private string $permission)
            {
            }

            public function supports(string $permission, mixed $resource, Context $ctx): bool
            {
                return true;
            }

            public function vote(UserIdentity $user, string $permission, mixed $resource, Context $ctx): Vote
            {
                return new Vote(
                    $permission === $this->permission && in_array($this->role, $user->roles(), true)
                        ? Vote::GRANT
                        : Vote::ABSTAIN
                );
            }

            public function priority(): int
            {
                return 0;
            }
        });

        $manager = new RecordingPermissionManager();
        $manager->setGate($gate);
        return $manager;
    }
}

/** Records every can() call so the tests can assert the Gate path was taken with the user's roles. */
final class RecordingPermissionManager extends PermissionManager
{
    /** @var list<array{0:string,1:string,2:string,3:mixed}> */
    public array $calls = [];

    public function can(string $userUuid, string $permission, string $resource, array $context = []): bool
    {
        $this->calls[] = [$userUuid, $permission, $resource, $context['roles'] ?? null];
        return parent::can($userUuid, $permission, $resource, $context);
    }
}

/**
 * Minimal controller double: AuthorizationTrait plus the CachedUserContextTrait methods it
 * relies on, backed by a fixed identity (no session data, so the session fast-path never grants).
 */
final class RequirePermissionHarness
{
    use AuthorizationTrait;

    public function __construct(private ?UserIdentity $user, private RecordingPermissionManager $manager)
    {
    }

    public function check(string $permission, string $resource = 'system'): void
    {
        $this->requirePermission($permission, $resource);
    }

    /** @return list<array{0:string,1:string,2:string,3:mixed}> */
    public function managerCalls(): array
    {
        return $this->manager->calls;
    }

    protected function permissionManager(): PermissionManager
    {
        return $this->manager;
    }

    protected function requireCachedAuthentication(?string $message = null): void
    {
        if ($this->user === null) {
            throw new UnauthorizedException('anonymous', 'authentication', 'system', 'Authentication required');
        }
    }

    /** @param array<string, mixed> $context */
    protected function hasCachedPermission(string $permission, string $resource = 'system', array $context = []): bool
    {
        return false;
    }

    protected function getCachedUser(): ?UserIdentity
    {
        return $this->user;
    }

    protected function getCachedUserUuid(): ?string
    {
        return $this->user?->id();
    }
}
