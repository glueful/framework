<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Container\Compile;

use Glueful\Container\Autowire\AutowireDefinition;
use Glueful\Container\Compile\DefinitionSignature;
use Glueful\Container\Definition\AliasDefinition;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Container\Definition\TaggedIteratorDefinition;
use Glueful\Container\Definition\ValueDefinition;
use PHPUnit\Framework\TestCase;

/**
 * The production container is compiled to a file named by a signature of the definitions it
 * was built from, so the artifact is reused across boots while nothing changed and replaced —
 * under a NEW path OPcache has never seen — the moment a service, alias, factory or tag changes.
 * The signature must be cheap (no serialization of closures) and stable across processes.
 */
final class DefinitionSignatureTest extends TestCase
{
    /** @return array<string, object> */
    private function defs(): array
    {
        return [
            'svc.a' => new AutowireDefinition('svc.a', \ArrayObject::class),
            'svc.alias' => new AliasDefinition('svc.alias', 'svc.a'),
            'svc.factory' => new FactoryDefinition('svc.factory', [self::class, 'make']),
            'svc.value' => new ValueDefinition('svc.value', 42),
            'tag.x' => new TaggedIteratorDefinition('tag.x', [['service' => 'svc.a', 'priority' => 0]]),
        ];
    }

    public static function make(): \ArrayObject
    {
        return new \ArrayObject();
    }

    public function testTheSameDefinitionsProduceTheSameSignatureRegardlessOfOrder(): void
    {
        $a = DefinitionSignature::of($this->defs());
        $b = DefinitionSignature::of(array_reverse($this->defs(), true));

        self::assertSame($a, $b);
        self::assertMatchesRegularExpression('/^[a-f0-9]{16,64}$/', $a);
    }

    public function testAChangedTargetClassOrTagChangesTheSignature(): void
    {
        $base = DefinitionSignature::of($this->defs());

        $changedClass = $this->defs();
        $changedClass['svc.a'] = new AutowireDefinition('svc.a', \SplStack::class);
        self::assertNotSame($base, DefinitionSignature::of($changedClass));

        $changedAlias = $this->defs();
        $changedAlias['svc.alias'] = new AliasDefinition('svc.alias', 'svc.factory');
        self::assertNotSame($base, DefinitionSignature::of($changedAlias));

        $changedTag = $this->defs();
        $changedTag['tag.x'] = new TaggedIteratorDefinition('tag.x', [
            ['service' => 'svc.a', 'priority' => 0],
            ['service' => 'svc.factory', 'priority' => 5],
        ]);
        self::assertNotSame($base, DefinitionSignature::of($changedTag));

        $added = $this->defs();
        $added['svc.new'] = new ValueDefinition('svc.new', 'x');
        self::assertNotSame($base, DefinitionSignature::of($added));
    }

    public function testClosureFactoriesAreIdentifiedByPlaceNotByInstance(): void
    {
        $one = ['f' => new FactoryDefinition('f', static fn (): int => 1)];
        $same = ['f' => new FactoryDefinition('f', static fn (): int => 1)]; // different line
        $again = $one; // same closure instance

        self::assertSame(DefinitionSignature::of($one), DefinitionSignature::of($again));
        self::assertNotSame(DefinitionSignature::of($one), DefinitionSignature::of($same));
    }

    public function testLiveObjectValuesContributeTheirClassNotTheirState(): void
    {
        $a = ['ctx' => new ValueDefinition('ctx', new \ArrayObject([1]))];
        $b = ['ctx' => new ValueDefinition('ctx', new \ArrayObject([2, 3]))];

        self::assertSame(DefinitionSignature::of($a), DefinitionSignature::of($b));
    }
}
