<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Extensions\DeclaresLoadOrder;
use Glueful\Extensions\ProviderOrderCycleException;
use Glueful\Extensions\ProviderOrderer;
use PHPUnit\Framework\TestCase;

final class OrdererFixtureA
{
}

final class OrdererFixtureB implements DeclaresLoadOrder
{
    public static function loadAfter(): array
    {
        return [OrdererFixtureA::class];
    }

    public static function loadPriority(): int
    {
        return 0;
    }
}

final class OrdererFixtureC implements DeclaresLoadOrder
{
    public static function loadAfter(): array
    {
        return ['Vendor\\Absent\\Provider'];
    }

    public static function loadPriority(): int
    {
        return -10;
    }
}

final class OrdererFixtureCycleX implements DeclaresLoadOrder
{
    public static function loadAfter(): array
    {
        return [OrdererFixtureCycleY::class];
    }

    public static function loadPriority(): int
    {
        return 0;
    }
}

final class OrdererFixtureCycleY implements DeclaresLoadOrder
{
    public static function loadAfter(): array
    {
        return [OrdererFixtureCycleX::class];
    }

    public static function loadPriority(): int
    {
        return 0;
    }
}

final class OrdererFixtureCycleTail implements DeclaresLoadOrder
{
    public static function loadAfter(): array
    {
        return [OrdererFixtureCycleX::class];
    }

    public static function loadPriority(): int
    {
        return 0;
    }
}

final class OrdererFixtureSelfCycle implements DeclaresLoadOrder
{
    public static function loadAfter(): array
    {
        return [self::class];
    }

    public static function loadPriority(): int
    {
        return 0;
    }
}

final class ProviderOrdererTest extends TestCase
{
    public function testNoMetadataPreservesInputOrder(): void
    {
        $in = [OrdererFixtureA::class, 'Vendor\\Missing\\Cls'];
        self::assertSame($in, ProviderOrderer::order($in));
    }

    public function testAfterEdgeReordersAcrossTheList(): void
    {
        // B declares after:A but appears first — the orderer must move it after A.
        $out = ProviderOrderer::order([OrdererFixtureB::class, OrdererFixtureA::class]);
        self::assertSame([OrdererFixtureA::class, OrdererFixtureB::class], $out);
    }

    public function testAbsentEdgeTargetIsIgnored(): void
    {
        $out = ProviderOrderer::order([OrdererFixtureC::class, OrdererFixtureA::class]);
        // C's absent edge is ignored; its negative priority pulls it first anyway.
        self::assertSame([OrdererFixtureC::class, OrdererFixtureA::class], $out);
    }

    public function testPriorityBreaksTiesThenOriginalPosition(): void
    {
        // C (priority -10) precedes B (0); both unconstrained relative to each other.
        $out = ProviderOrderer::order(
            [OrdererFixtureA::class, OrdererFixtureB::class, OrdererFixtureC::class]
        );
        self::assertSame(
            [OrdererFixtureC::class, OrdererFixtureA::class, OrdererFixtureB::class],
            $out
        );
    }

    public function testCycleThrowsNamingTheCycleAndDownstreamBlockedProvidersAccurately(): void
    {
        try {
            ProviderOrderer::order([
                OrdererFixtureCycleX::class,
                OrdererFixtureCycleY::class,
                OrdererFixtureCycleTail::class,
            ]);
            self::fail('Expected a provider-order cycle.');
        } catch (ProviderOrderCycleException $e) {
            self::assertSame([
                OrdererFixtureCycleX::class,
                OrdererFixtureCycleY::class,
                OrdererFixtureCycleTail::class,
            ], $e->blockedProviders);
            self::assertStringContainsString('blocked by a load-order cycle', $e->getMessage());
        }
    }

    public function testSelfDependencyIsACycleRatherThanAnIgnoredEdge(): void
    {
        $this->expectException(ProviderOrderCycleException::class);
        ProviderOrderer::order([OrdererFixtureSelfCycle::class]);
    }

    public function testDeterministicAcrossRepeatedRuns(): void
    {
        $in = [OrdererFixtureB::class, OrdererFixtureC::class, OrdererFixtureA::class];
        self::assertSame(ProviderOrderer::order($in), ProviderOrderer::order($in));
    }
}
