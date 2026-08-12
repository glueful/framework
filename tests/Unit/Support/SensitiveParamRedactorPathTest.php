<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support;

use Glueful\Support\SensitiveParamRedactor;
use PHPUnit\Framework\TestCase;

/**
 * Path-segment redaction: hosts that carry a bearer credential inside the URL
 * PATH (magic links, signed payment links, one-time download URLs) register the
 * route template so the credential segment never reaches a log sink.
 */
final class SensitiveParamRedactorPathTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2';

    protected function setUp(): void
    {
        parent::setUp();
        SensitiveParamRedactor::configureSensitivePaths([]);
    }

    protected function tearDown(): void
    {
        // Static registration must never leak into other tests.
        SensitiveParamRedactor::configureSensitivePaths([]);
        parent::tearDown();
    }

    public function testNoPatternsConfiguredLeavesPathsByteIdentical(): void
    {
        $path = '/checkout/pay/' . self::TOKEN;

        self::assertSame($path, SensitiveParamRedactor::sanitizePath($path));
        self::assertSame($path, SensitiveParamRedactor::sanitizeUrl($path));
    }

    public function testPlaceholderSegmentIsRedacted(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        self::assertSame(
            '/checkout/pay/' . SensitiveParamRedactor::REDACTED,
            SensitiveParamRedactor::sanitizePath('/checkout/pay/' . self::TOKEN)
        );
    }

    public function testPercentEncodedSegmentIsRedacted(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        $encoded = '/checkout/pay/' . rawurlencode(self::TOKEN);
        $sanitized = SensitiveParamRedactor::sanitizePath($encoded);

        self::assertIsString($sanitized);
        self::assertStringNotContainsString(self::TOKEN, $sanitized);
        self::assertSame('/checkout/pay/' . SensitiveParamRedactor::REDACTED, $sanitized);
    }

    public function testPercentEncodedLiteralSegmentsStillMatch(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        // '%63' is 'c' — an encoded literal prefix must not defeat the match.
        $sanitized = SensitiveParamRedactor::sanitizePath('/%63heckout/pay/' . self::TOKEN);

        self::assertIsString($sanitized);
        self::assertStringNotContainsString(self::TOKEN, $sanitized);
    }

    public function testLiteralSegmentsMatchCaseInsensitively(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        $sanitized = SensitiveParamRedactor::sanitizePath('/Checkout/PAY/' . self::TOKEN);

        self::assertIsString($sanitized);
        self::assertStringNotContainsString(self::TOKEN, $sanitized);
    }

    public function testNonMatchingPathsAreUnchanged(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        self::assertSame('/checkout/pay', SensitiveParamRedactor::sanitizePath('/checkout/pay'));
        self::assertSame('/orders/42', SensitiveParamRedactor::sanitizePath('/orders/42'));
        self::assertSame('/checkout/refund/x', SensitiveParamRedactor::sanitizePath('/checkout/refund/x'));
        self::assertSame('/api/checkout/pay/x', SensitiveParamRedactor::sanitizePath('/api/checkout/pay/x'));
    }

    public function testTrailingSegmentsBeyondThePatternArePreserved(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        self::assertSame(
            '/checkout/pay/' . SensitiveParamRedactor::REDACTED . '/receipt',
            SensitiveParamRedactor::sanitizePath('/checkout/pay/' . self::TOKEN . '/receipt')
        );
    }

    public function testSingleSegmentWildcardMatchesAndIsPreserved(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/orders/*/pay/{token}']);

        self::assertSame(
            '/orders/42/pay/' . SensitiveParamRedactor::REDACTED,
            SensitiveParamRedactor::sanitizePath('/orders/42/pay/' . self::TOKEN)
        );
    }

    public function testMultiplePatternsAreAllApplied(): void
    {
        SensitiveParamRedactor::configureSensitivePaths([
            '/checkout/pay/{token}',
            'downloads/{signature}',
        ]);

        self::assertSame(
            '/checkout/pay/' . SensitiveParamRedactor::REDACTED,
            SensitiveParamRedactor::sanitizePath('/checkout/pay/' . self::TOKEN)
        );
        // Leading slash in the registered template is optional.
        self::assertSame(
            '/downloads/' . SensitiveParamRedactor::REDACTED,
            SensitiveParamRedactor::sanitizePath('/downloads/' . self::TOKEN)
        );
    }

    public function testEmptySegmentsAndBlankPatternsAreIgnored(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['', '   ', '/checkout/pay/{token}']);

        self::assertSame('/checkout/pay/', SensitiveParamRedactor::sanitizePath('/checkout/pay/'));
        self::assertSame('', SensitiveParamRedactor::sanitizePath(''));
        self::assertNull(SensitiveParamRedactor::sanitizePath(null));
        self::assertSame('/', SensitiveParamRedactor::sanitizePath('/'));
    }

    public function testRelativePathsWithoutLeadingSlashAreRedacted(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        self::assertSame(
            'checkout/pay/' . SensitiveParamRedactor::REDACTED,
            SensitiveParamRedactor::sanitizePath('checkout/pay/' . self::TOKEN)
        );
    }

    public function testSanitizeUrlRedactsPathAndQueryTogether(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        $sanitized = SensitiveParamRedactor::sanitizeUrl(
            'https://shop.test/checkout/pay/' . self::TOKEN . '?access_token=leak&keep=1'
        );

        self::assertIsString($sanitized);
        self::assertStringNotContainsString(self::TOKEN, $sanitized);
        self::assertStringNotContainsString('leak', $sanitized);
        self::assertStringContainsString('keep=1', $sanitized);
        self::assertStringStartsWith('https://shop.test/checkout/pay/' . SensitiveParamRedactor::REDACTED, $sanitized);
    }

    public function testExistingQueryAndUrlBehaviourIsUnaffectedByRegistration(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        self::assertSame('https://host.test/path?x=1', SensitiveParamRedactor::sanitizeUrl(
            'https://user:pass@host.test/path?x=1#frag'
        ));
        self::assertSame(SensitiveParamRedactor::REDACTED, SensitiveParamRedactor::sanitizeUrl('http://'));
        self::assertNull(SensitiveParamRedactor::sanitizeUrl(null));
        self::assertSame('', SensitiveParamRedactor::sanitizeUrl(''));
    }

    public function testRegisteredPatternsAreReadableForDiagnostics(): void
    {
        self::assertSame([], SensitiveParamRedactor::sensitivePathPatterns());

        SensitiveParamRedactor::configureSensitivePaths([' /checkout/pay/{token} ', '']);

        self::assertSame(['/checkout/pay/{token}'], SensitiveParamRedactor::sensitivePathPatterns());
    }
}
