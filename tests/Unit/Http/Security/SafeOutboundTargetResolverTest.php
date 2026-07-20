<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Http\Security;

use Glueful\Http\Exceptions\HttpClientException;
use Glueful\Http\Security\ResolvedOutboundTarget;
use Glueful\Http\Security\SafeOutboundTargetResolver;
use PHPUnit\Framework\TestCase;

/**
 * SafeOutboundTargetResolver is the single SSRF policy for outbound requests, shared
 * by Client::safeRequest()/safeRequestAsync() (resolveSafeFetch — backward compatible)
 * and the strict webhook profile (resolveWebhook). DNS resolution is injected via a
 * closure so these tests are deterministic and do not perform real network I/O; the
 * IP-literal fast path (used by the resolveSafeFetch regression tests) never touches
 * the injected closure at all, exactly mirroring the pre-extraction Client behavior.
 */
final class SafeOutboundTargetResolverTest extends TestCase
{
    // ---------------------------------------------------------------
    // resolveWebhook() — strict profile
    // ---------------------------------------------------------------

    public function testResolveWebhookAcceptsPublicHttpsUrlAndPinsResolvedAddress(): void
    {
        $resolver = $this->resolverWithDns(['shop.example.test' => ['93.184.216.34']]);

        $target = $resolver->resolveWebhook('https://shop.example.test/hooks/orders');

        $this->assertInstanceOf(ResolvedOutboundTarget::class, $target);
        $this->assertSame('shop.example.test', $target->host);
        $this->assertSame('93.184.216.34', $target->ip);
        $this->assertSame(443, $target->port);
        $this->assertSame('https://shop.example.test/hooks/orders', $target->canonicalUrl);
    }

    public function testResolveWebhookPreservesQueryStringInCanonicalUrl(): void
    {
        $resolver = $this->resolverWithDns(['shop.example.test' => ['93.184.216.34']]);

        $target = $resolver->resolveWebhook('https://shop.example.test/hooks?tenant=abc');

        $this->assertSame('https://shop.example.test/hooks?tenant=abc', $target->canonicalUrl);
    }

    public function testResolveWebhookRejectsHttpScheme(): void
    {
        $resolver = $this->resolverWithDns(['shop.example.test' => ['93.184.216.34']]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('only https is allowed');

        $resolver->resolveWebhook('http://shop.example.test/hooks');
    }

    public function testResolveWebhookRejectsCredentials(): void
    {
        $resolver = $this->resolverWithDns(['shop.example.test' => ['93.184.216.34']]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('credentials are not allowed');

        $resolver->resolveWebhook('https://user:pass@shop.example.test/hooks');
    }

    public function testResolveWebhookRejectsFragment(): void
    {
        $resolver = $this->resolverWithDns(['shop.example.test' => ['93.184.216.34']]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('fragment is not allowed');

        $resolver->resolveWebhook('https://shop.example.test/hooks#frag');
    }

    public function testResolveWebhookRejectsNonDefaultPort(): void
    {
        $resolver = $this->resolverWithDns(['shop.example.test' => ['93.184.216.34']]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('default HTTPS port');

        $resolver->resolveWebhook('https://shop.example.test:8443/hooks');
    }

    public function testResolveWebhookRejectsIpv4LiteralHost(): void
    {
        $resolver = $this->resolverWithDns([]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('IP-literal hosts are not allowed');

        $resolver->resolveWebhook('https://93.184.216.34/hooks');
    }

    public function testResolveWebhookRejectsIpv6LiteralHost(): void
    {
        $resolver = $this->resolverWithDns([]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('IP-literal hosts are not allowed');

        $resolver->resolveWebhook('https://[2001:db8::1]/hooks');
    }

    public function testResolveWebhookRejectsPrivateIpv4Resolution(): void
    {
        $resolver = $this->resolverWithDns(['internal.example.test' => ['10.0.0.5']]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('private or reserved');

        $resolver->resolveWebhook('https://internal.example.test/hooks');
    }

    public function testResolveWebhookRejectsLoopbackResolution(): void
    {
        $resolver = $this->resolverWithDns(['loopback.example.test' => ['127.0.0.1']]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('private or reserved');

        $resolver->resolveWebhook('https://loopback.example.test/hooks');
    }

    public function testResolveWebhookRejectsLinkLocalMetadataResolution(): void
    {
        $resolver = $this->resolverWithDns(['metadata.example.test' => ['169.254.169.254']]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('private or reserved');

        $resolver->resolveWebhook('https://metadata.example.test/hooks');
    }

    public function testResolveWebhookRejectsIpv6LoopbackAndLinkLocalResolution(): void
    {
        $loopback = $this->resolverWithDns(['v6loop.example.test' => ['::1']]);
        try {
            $loopback->resolveWebhook('https://v6loop.example.test/hooks');
            $this->fail('Expected HttpClientException for IPv6 loopback resolution');
        } catch (HttpClientException $e) {
            $this->assertStringContainsString('private or reserved', $e->getMessage());
        }

        $linkLocal = $this->resolverWithDns(['v6link.example.test' => ['fe80::1']]);
        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('private or reserved');
        $linkLocal->resolveWebhook('https://v6link.example.test/hooks');
    }

    public function testResolveWebhookRejectsCgnatResolution(): void
    {
        // 100.64.0.0/10 (RFC6598 CGNAT) is NOT covered by FILTER_FLAG_NO_PRIV_RANGE /
        // FILTER_FLAG_NO_RES_RANGE — this is the supplemental blocked-CIDR check.
        $resolver = $this->resolverWithDns(['cgnat.example.test' => ['100.64.0.5']]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('private or reserved');

        $resolver->resolveWebhook('https://cgnat.example.test/hooks');
    }

    public function testResolveWebhookRejectsNat64EmbeddedLoopbackResolution(): void
    {
        // 64:ff9b::/96 (NAT64) with an embedded 127.0.0.1 — must decode the embedded
        // IPv4 and reject because 127.0.0.0/8 is blocked, not just the /96 shell.
        $resolver = $this->resolverWithDns(['nat64.example.test' => ['64:ff9b::7f00:1']]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('private or reserved');

        $resolver->resolveWebhook('https://nat64.example.test/hooks');
    }

    public function testResolveWebhookRejects6to4EmbeddedLoopbackResolution(): void
    {
        // 2002::/16 (6to4) with an embedded 127.0.0.1 (2002:7f00:0001:: decodes to
        // 127.0.0.1 in bytes 2-5) — must be rejected via the decoded IPv4 check.
        $resolver = $this->resolverWithDns(['sixtofour.example.test' => ['2002:7f00:1::']]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('private or reserved');

        $resolver->resolveWebhook('https://sixtofour.example.test/hooks');
    }

    public function testResolveWebhookRejectsIanaProtocolAssignmentResolution(): void
    {
        // 192.0.0.0/24 (IANA IETF Protocol Assignments) — reserved space not covered
        // by FILTER_FLAG_NO_RES_RANGE.
        $resolver = $this->resolverWithDns(['iana.example.test' => ['192.0.0.1']]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('private or reserved');

        $resolver->resolveWebhook('https://iana.example.test/hooks');
    }

    public function testResolveWebhookRejectsBenchmarkingRangeResolution(): void
    {
        // 198.18.0.0/15 (RFC2544 benchmarking) — reserved space not covered by
        // FILTER_FLAG_NO_RES_RANGE.
        $resolver = $this->resolverWithDns(['bench.example.test' => ['198.18.0.1']]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('private or reserved');

        $resolver->resolveWebhook('https://bench.example.test/hooks');
    }

    public function testResolveWebhookRejectsIpv4MappedMetadataResolution(): void
    {
        // ::ffff:0:0/96 (IPv4-mapped) wrapping the cloud metadata address — must
        // decode the embedded IPv4 and reject because 169.254.0.0/16 is blocked.
        $resolver = $this->resolverWithDns(['mapped.example.test' => ['::ffff:169.254.169.254']]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('private or reserved');

        $resolver->resolveWebhook('https://mapped.example.test/hooks');
    }

    public function testResolveWebhookRejectsWhenAnyOfMultipleARecordsIsPrivate(): void
    {
        // Public first, private second — proves ALL resolved addresses are checked,
        // not just the one that would be pinned.
        $resolver = $this->resolverWithDns(['mixed.example.test' => ['93.184.216.34', '10.0.0.5']]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('private or reserved');

        $resolver->resolveWebhook('https://mixed.example.test/hooks');
    }

    public function testResolveWebhookRejectsWhenHostCannotBeResolved(): void
    {
        $resolver = $this->resolverWithDns(['nowhere.example.test' => []]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('could not be resolved');

        $resolver->resolveWebhook('https://nowhere.example.test/hooks');
    }

    public function testResolveWebhookRejectsMalformedIdnHost(): void
    {
        $resolver = $this->resolverWithDns([]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('malformed international domain name');

        // A raw space is not a valid domain label character under STD3 rules.
        $resolver->resolveWebhook('https://exa mple.test/hooks');
    }

    public function testResolveWebhookRejectsAmbiguousHostEncoding(): void
    {
        $resolver = $this->resolverWithDns([]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('ambiguous host encoding');

        // U+FF0E (fullwidth full stop) is not literally '.' but UTS46 silently
        // canonicalizes it to one, splitting one apparent label into two.
        $resolver->resolveWebhook("https://shop\u{FF0E}example.test/hooks");
    }

    public function testResolveWebhookAcceptsAndPunycodesAGenuineUnicodeIdnHost(): void
    {
        // "例え" (Japanese) — a genuine multi-byte IDN label, not a dot look-alike.
        // Also proves parse_url()'s host-corruption quirk for 3-byte UTF-8 sequences
        // (see parseUrlSafely()) doesn't cause a legitimate IDN host to be mangled.
        $resolver = $this->resolverWithDns(['xn--r8jz45g.example.test' => ['93.184.216.34']]);

        $target = $resolver->resolveWebhook("https://\u{4F8B}\u{3048}.example.test/hooks");

        $this->assertSame('xn--r8jz45g.example.test', $target->host);
        $this->assertSame('93.184.216.34', $target->ip);
        $this->assertSame('https://xn--r8jz45g.example.test/hooks', $target->canonicalUrl);
    }

    public function testResolveWebhookOnlyResolvesDnsOnce(): void
    {
        $calls = 0;
        $resolver = new SafeOutboundTargetResolver(function (string $host) use (&$calls): array {
            $calls++;
            return ['93.184.216.34'];
        });

        $resolver->resolveWebhook('https://shop.example.test/hooks');

        $this->assertSame(1, $calls);
    }

    // ---------------------------------------------------------------
    // resolveSafeFetch() — backward-compatible profile (regression)
    // ---------------------------------------------------------------

    public function testResolveSafeFetchAcceptsHttpAndHttpsIpLiteralsWithoutInvokingDnsLookup(): void
    {
        $resolver = new SafeOutboundTargetResolver(function (string $host): array {
            $this->fail('DNS lookup should not be invoked for an IP-literal host');
        });

        $target = $resolver->resolveSafeFetch('https://93.184.216.34/image.png');

        $this->assertSame('93.184.216.34', $target->host);
        $this->assertSame('93.184.216.34', $target->ip);
        $this->assertSame(443, $target->port);
    }

    public function testResolveSafeFetchRejectsPrivateIpByDefault(): void
    {
        $resolver = new SafeOutboundTargetResolver();

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('Unsafe URL');

        $resolver->resolveSafeFetch('http://169.254.169.254/latest/meta-data');
    }

    public function testResolveSafeFetchAllowsPrivateHostWhenOptedIn(): void
    {
        $resolver = new SafeOutboundTargetResolver();

        $target = $resolver->resolveSafeFetch('http://10.0.0.5/health', true);

        $this->assertSame('10.0.0.5', $target->host);
        $this->assertSame('10.0.0.5', $target->ip);
    }

    public function testResolveSafeFetchRejectsNonHttpScheme(): void
    {
        $resolver = new SafeOutboundTargetResolver();

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('only http and https schemes are allowed');

        $resolver->resolveSafeFetch('ftp://93.184.216.34/image.png');
    }

    public function testResolveSafeFetchRejectsWhenHostCannotBeResolved(): void
    {
        $resolver = $this->resolverWithDns(['nowhere.example.test' => []]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('host could not be resolved');

        $resolver->resolveSafeFetch('https://nowhere.example.test/x');
    }

    public function testResolveSafeFetchAcceptsAWebhookRejectedShapeLikeCredentialsOrPorts(): void
    {
        // resolveSafeFetch never adopted the strict webhook rules — credentials and
        // non-default ports on an otherwise-public IP literal are still accepted.
        $resolver = new SafeOutboundTargetResolver();

        $target = $resolver->resolveSafeFetch('https://user:pass@93.184.216.34:8443/x');

        $this->assertSame('93.184.216.34', $target->host);
        $this->assertSame(8443, $target->port);
    }

    public function testResolveSafeFetchIsUnaffectedByTheWebhookOnlySupplementalBlocklist(): void
    {
        // The CGNAT/NAT64/6to4/IANA-reserved supplemental check added for
        // resolveWebhook() must NOT be applied to resolveSafeFetch() — its
        // blocked-range policy stays exactly filter_var's NO_PRIV_RANGE |
        // NO_RES_RANGE flags, byte-identical to the pre-extraction behavior.
        $resolver = $this->resolverWithDns(['cgnat.example.test' => ['100.64.0.5']]);

        $target = $resolver->resolveSafeFetch('https://cgnat.example.test/x');

        $this->assertSame('100.64.0.5', $target->ip);
    }

    /**
     * @param array<string, list<string>> $records
     */
    private function resolverWithDns(array $records): SafeOutboundTargetResolver
    {
        return new SafeOutboundTargetResolver(function (string $host) use ($records): array {
            return $records[$host] ?? [];
        });
    }
}
