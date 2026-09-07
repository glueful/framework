<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Container\Compile;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Compile\ContainerCompiler;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Container\Autowire\AutowireDefinition;
use Glueful\Container\RebindableContainer;
use Glueful\Container\Exception\ContainerException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The compiled container never engaged for a real application: every static factory (the
 * framework's own core providers register dozens) was "unsupported", and the reserved
 * ApplicationContext value is an object no code export can reproduce. Production silently ran
 * the runtime container with a warning on every boot.
 */
final class ContainerCompilerTest extends TestCase
{
    private static int $seq = 0;

    public function testStaticArrayFactoryCompilesToAStaticCallReceivingTheContainer(): void
    {
        $c = $this->compiled([
            'svc' => new FactoryDefinition('svc', [CompilerFixtureFactory::class, 'make']),
        ]);

        $made = $c->get('svc');

        self::assertInstanceOf(CompilerFixtureProduct::class, $made);
        self::assertSame($c, $made->container, 'the factory receives the compiled container');
        self::assertSame($made, $c->get('svc'), 'shared factories are memoized');
    }

    public function testStringFactoryCompilesAndNonSharedFactoriesRebuild(): void
    {
        $c = $this->compiled([
            'svc' => new FactoryDefinition('svc', CompilerFixtureFactory::class . '::make', shared: false),
        ]);

        self::assertNotSame($c->get('svc'), $c->get('svc'), 'non-shared factories build a fresh instance');
    }

    public function testClosureFactoriesCompileAsRuntimeInjectedFactories(): void
    {
        $calls = 0;
        $closure = static function (ContainerInterface $c) use (&$calls): object {
            $calls++;
            return new CompilerFixtureProduct($c);
        };
        $c = $this->compiled(['svc' => new FactoryDefinition('svc', $closure)], hydrate: false);

        self::assertSame(['svc'], $c::RUNTIME_FACTORY_IDS);
        try {
            $c->get('svc');
            self::fail('an un-hydrated runtime factory must not resolve');
        } catch (ContainerException $e) {
            self::assertStringContainsString('svc', $e->getMessage());
        }

        $c->withRuntimeFactories(['svc' => $closure]);
        $made = $c->get('svc');
        self::assertInstanceOf(CompilerFixtureProduct::class, $made);
        self::assertSame($c, $made->container, 'the closure receives the compiled container');
        self::assertSame($made, $c->get('svc'), 'shared: memoized');
        self::assertSame(1, $calls);
    }

    public function testObjectValuesAreRuntimeValuesInjectedAfterConstruction(): void
    {
        $context = new ApplicationContext('/tmp', 'production');
        $c = $this->compiled([
            ApplicationContext::class => new ValueDefinition(ApplicationContext::class, $context),
        ], hydrate: false);

        self::assertSame([ApplicationContext::class], $c::RUNTIME_VALUE_IDS);
        self::assertTrue($c->has(ApplicationContext::class));

        try {
            $c->get(ApplicationContext::class);
            self::fail('an un-hydrated runtime value must not resolve to null');
        } catch (ContainerException $e) {
            self::assertStringContainsString(ApplicationContext::class, $e->getMessage());
        }

        $c->withRuntimeValues([ApplicationContext::class => $context]);
        self::assertSame($context, $c->get(ApplicationContext::class));
    }

    public function testTheContainerSelfReferenceCompilesToThis(): void
    {
        $someContainer = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                return null;
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
        $c = $this->compiled([
            ContainerInterface::class => new ValueDefinition(ContainerInterface::class, $someContainer),
        ]);

        self::assertSame($c, $c->get(ContainerInterface::class));
    }

    public function testRuntimeLoadOverridesACompiledDefinitionAndAddsNewOnes(): void
    {
        $c = $this->compiled([
            CompilerFixtureProduct::class => new AutowireDefinition(CompilerFixtureProduct::class, CompilerFixtureProduct::class),
        ]);

        self::assertInstanceOf(RebindableContainer::class, $c, 'compiled containers accept runtime rebinds');

        $override = new CompilerFixtureProduct($c);
        $c->load([
            CompilerFixtureProduct::class => new FactoryDefinition(
                CompilerFixtureProduct::class,
                static fn (ContainerInterface $container): object => $override,
            ),
            'late.value' => 'added at runtime',
            'late.factory' => static fn (ContainerInterface $container): object => new \stdClass(),
        ]);

        self::assertSame($override, $c->get(CompilerFixtureProduct::class), 'a runtime rebind wins over the compiled definition');
        self::assertTrue($c->has('late.value'));
        self::assertSame('added at runtime', $c->get('late.value'));
        self::assertSame($c->get('late.factory'), $c->get('late.factory'), 'runtime factories are shared by default');
    }

    /** @param array<string, object> $definitions */
    private function compiled(array $definitions, bool $hydrate = true): object
    {
        $class = 'Compiled' . ++self::$seq;
        $namespace = __NAMESPACE__ . '\\Generated';
        $code = (new ContainerCompiler())->compile($definitions, $class, $namespace);
        eval(substr($code, strlen('<?php')));

        $fqcn = $namespace . '\\' . $class;
        $container = new $fqcn();
        if ($hydrate && $container::RUNTIME_VALUE_IDS !== []) {
            $values = [];
            foreach ($container::RUNTIME_VALUE_IDS as $id) {
                $values[$id] = $definitions[$id]->getValue();
            }
            $container->withRuntimeValues($values);
        }
        if ($hydrate && $container::RUNTIME_FACTORY_IDS !== []) {
            $factories = [];
            foreach ($container::RUNTIME_FACTORY_IDS as $id) {
                $factories[$id] = $definitions[$id]->getFactory();
            }
            $container->withRuntimeFactories($factories);
        }

        return $container;
    }
}

final class CompilerFixtureProduct
{
    public function __construct(public readonly ContainerInterface $container)
    {
    }
}

final class CompilerFixtureFactory
{
    public static function make(ContainerInterface $container): CompilerFixtureProduct
    {
        return new CompilerFixtureProduct($container);
    }
}
