<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Extensions\ExtensionCandidate;
use Glueful\Extensions\ExtensionResolver;
use Glueful\Extensions\ResolverError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExtensionResolver::class)]
final class ExtensionResolverTest extends TestCase
{
    /**
     * @param list<ExtensionCandidate> $list
     * @return array<string, ExtensionCandidate>
     */
    private function candidates(array $list): array
    {
        $map = [];
        foreach ($list as $c) {
            $map[$c->name] = $c;
        }
        return $map;
    }

    /** @param list<string> $deps */
    private function cand(string $provider, array $deps = [], ?string $reqGlueful = null): ExtensionCandidate
    {
        return new ExtensionCandidate('pkg/' . md5($provider), $provider, $reqGlueful, $deps);
    }

    public function testEmptyEnabledYieldsNothing(): void
    {
        $r = new ExtensionResolver();
        $res = $r->resolve($this->candidates([$this->cand('A')]), [], '1.46.0');
        $this->assertSame([], $res->providers);
        $this->assertSame([], $res->errors);
    }

    public function testEnabledOrderPreservedWhenNoDeps(): void
    {
        $r = new ExtensionResolver();
        $res = $r->resolve($this->candidates([$this->cand('A'), $this->cand('B')]), ['B', 'A'], '1.46.0');
        $this->assertSame(['B', 'A'], $res->providers);
        $this->assertFalse($res->hasErrors());
    }

    public function testEnabledEntryNotACandidateIsMissingProviderError(): void
    {
        $r = new ExtensionResolver();
        $res = $r->resolve($this->candidates([$this->cand('A')]), ['A', 'Ghost'], '1.46.0');
        $this->assertSame(['A'], $res->providers);
        $this->assertCount(1, $res->errors);
        $this->assertSame(ResolverError::MISSING_PROVIDER, $res->errors[0]->kind);
        $this->assertSame('Ghost', $res->errors[0]->provider);
    }

    public function testEnabledExtensionWithUnenabledDependencyErrors(): void
    {
        $r = new ExtensionResolver();
        // A requires B (by provider FQCN), but only A is enabled
        $cands = $this->candidates([$this->cand('A', ['B']), $this->cand('B')]);
        $res = $r->resolve($cands, ['A'], '1.46.0');
        $this->assertCount(1, $res->errors);
        $this->assertSame(ResolverError::MISSING_DEPENDENCY, $res->errors[0]->kind);
    }

    public function testDependencyOrderedBeforeDependent(): void
    {
        $r = new ExtensionResolver();
        $cands = $this->candidates([$this->cand('A', ['B']), $this->cand('B')]);
        // Both enabled, declared dependent-first; resolver must order B before A
        $res = $r->resolve($cands, ['A', 'B'], '1.46.0');
        $this->assertFalse($res->hasErrors());
        $this->assertSame(['B', 'A'], $res->providers);
    }

    public function testVersionMismatchErrors(): void
    {
        $r = new ExtensionResolver();
        $cands = $this->candidates([$this->cand('A', [], '>=2.0.0')]);
        $res = $r->resolve($cands, ['A'], '1.46.0');
        $this->assertCount(1, $res->errors);
        $this->assertSame(ResolverError::VERSION_MISMATCH, $res->errors[0]->kind);
    }

    public function testCycleErrors(): void
    {
        $r = new ExtensionResolver();
        $cands = $this->candidates([$this->cand('A', ['B']), $this->cand('B', ['A'])]);
        $res = $r->resolve($cands, ['A', 'B'], '1.46.0');
        $this->assertTrue($res->hasErrors());
        $kinds = array_map(fn($e) => $e->kind, $res->errors);
        $this->assertContains(ResolverError::DEPENDENCY_CYCLE, $kinds);
    }

    public function testResolutionIsEnvironmentIndependent(): void
    {
        $r = new ExtensionResolver();
        $cands = $this->candidates([$this->cand('A'), $this->cand('B', ['A'])]);

        putenv('APP_ENV=development');
        $dev = $r->resolve($cands, ['A', 'B'], '1.46.0');
        putenv('APP_ENV=production');
        $prod = $r->resolve($cands, ['A', 'B'], '1.46.0');
        putenv('APP_ENV');

        $this->assertSame($dev->providers, $prod->providers);
        $this->assertEquals($dev->errors, $prod->errors);
    }
}
