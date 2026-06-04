<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Extensions\ServiceProvider;
use Glueful\Permissions\{Context, Vote, VoterInterface};
use Glueful\Auth\UserIdentity;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;

final class ServiceProviderGateHooksTest extends TestCase
{
    public function test_base_provider_declares_no_voters_or_policies(): void
    {
        $provider = new class ($this->createMock(ContainerInterface::class)) extends ServiceProvider {};
        self::assertSame([], $provider->voters());
        self::assertSame([], $provider->policies());
    }

    public function test_subclass_can_declare_voters_and_policies(): void
    {
        $voter = new class implements VoterInterface {
            public function supports(string $permission, mixed $resource, Context $ctx): bool
            {
                return true;
            }
            public function vote(UserIdentity $user, string $permission, mixed $resource, Context $ctx): Vote
            {
                return new Vote(Vote::ABSTAIN);
            }
            public function priority(): int
            {
                return 50;
            }
        };

        $provider = new class ($this->createMock(ContainerInterface::class), $voter) extends ServiceProvider {
            private object $v;
            public function __construct(ContainerInterface $app, object $v)
            {
                parent::__construct($app);
                $this->v = $v;
            }
            public function voters(): array
            {
                return [$this->v];
            }
            public function policies(): array
            {
                return ['posts' => 'App\\Policies\\PostPolicy'];
            }
        };

        self::assertCount(1, $provider->voters());
        self::assertSame(['posts' => 'App\\Policies\\PostPolicy'], $provider->policies());
    }
}
