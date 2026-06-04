<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Auth\UserIdentity;
use Glueful\Extensions\{ExtensionManager, ServiceProvider};
use Glueful\Permissions\{Context, Gate, PolicyInterface, PolicyRegistry, Vote, VoterInterface};
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;

final class RegisterProviderGateExtensionsTest extends TestCase
{
    public function test_registers_voters_on_gate_and_policies_in_registry(): void
    {
        $gate = new Gate('affirmative', false);
        $policyRegistry = new PolicyRegistry();

        $grantingVoter = new class implements VoterInterface {
            public function supports(string $permission, mixed $resource, Context $ctx): bool
            {
                return true;
            }
            public function vote(UserIdentity $user, string $permission, mixed $resource, Context $ctx): Vote
            {
                return new Vote(Vote::GRANT);
            }
            public function priority(): int
            {
                return 1;
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            fn(string $id) => in_array($id, [Gate::class, PolicyRegistry::class], true)
        );
        $container->method('get')->willReturnCallback(fn(string $id) => match ($id) {
            Gate::class => $gate,
            PolicyRegistry::class => $policyRegistry,
            default => throw new \RuntimeException("unexpected get($id)"),
        });

        $provider = new class ($container, $grantingVoter) extends ServiceProvider {
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
                return ['posts' => FakePolicy::class];
            }
        };

        $manager = new ExtensionManager($container);
        $ref = new \ReflectionProperty(ExtensionManager::class, 'providers');
        $ref->setAccessible(true);
        $ref->setValue($manager, ['P' => $provider]);

        $manager->registerProviderGateExtensions();

        self::assertSame(Vote::GRANT, $gate->decide(new UserIdentity('u1'), 'anything', null, new Context()));
        self::assertInstanceOf(FakePolicy::class, $policyRegistry->get('posts'));
    }
}

final class FakePolicy implements PolicyInterface
{
    public function view(UserIdentity $user, mixed $resource, Context $ctx): ?bool
    {
        return true;
    }
    public function create(UserIdentity $user, mixed $resource, Context $ctx): ?bool
    {
        return true;
    }
    public function update(UserIdentity $user, mixed $resource, Context $ctx): ?bool
    {
        return true;
    }
    public function delete(UserIdentity $user, mixed $resource, Context $ctx): ?bool
    {
        return true;
    }
}
