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
    /** Reserved characters, so rawurlencode() genuinely changes the string. */
    private const TOKEN = 'sk_live+9f=2a';

    /** A credential whose own '/' becomes a segment boundary once decoded. */
    private const SLASH_TOKEN = 'sk_live/9f+2a';

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
        // Anchored: without a base URL, a prefixed path is NOT a match...
        self::assertSame('/api/checkout/pay/x', SensitiveParamRedactor::sanitizePath('/api/checkout/pay/x'));
        // ...and a base URL that does not prefix the path changes nothing either.
        self::assertSame(
            '/api/checkout/pay/x',
            SensitiveParamRedactor::sanitizePath('/api/checkout/pay/x', '/admin')
        );
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

    // --- Router-normalized matching -------------------------------------------------
    //
    // Router::match() normalizes with '/' . ltrim(rawurldecode($path), '/') BEFORE
    // splitting, so these forms all reach the same live route. A matcher that splits
    // the raw path would log the live credential in full.

    public function testCollapsedLeadingSlashesAreRedacted(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        $sanitized = SensitiveParamRedactor::sanitizePath('//checkout/pay/' . self::TOKEN);

        self::assertIsString($sanitized);
        self::assertStringNotContainsString(self::TOKEN, $sanitized);
        self::assertSame('/checkout/pay/' . SensitiveParamRedactor::REDACTED, $sanitized);
    }

    public function testRepeatedInteriorSlashesAreRedacted(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        $sanitized = SensitiveParamRedactor::sanitizePath('/checkout//pay/' . self::TOKEN);

        self::assertIsString($sanitized);
        self::assertStringNotContainsString(self::TOKEN, $sanitized);
    }

    public function testEncodedSlashSeparatorIsRedacted(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        $sanitized = SensitiveParamRedactor::sanitizePath('/checkout%2Fpay/' . self::TOKEN);

        self::assertIsString($sanitized);
        self::assertStringNotContainsString(self::TOKEN, $sanitized);
        self::assertSame('/checkout/pay/' . SensitiveParamRedactor::REDACTED, $sanitized);
    }

    public function testEncodedSlashInsideTheCredentialDoesNotSplitTheSecret(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        $sanitized = SensitiveParamRedactor::sanitizePath('/checkout/pay/' . rawurlencode(self::SLASH_TOKEN));

        self::assertIsString($sanitized);
        // Neither the whole credential nor either half of it survives.
        self::assertStringNotContainsString(self::SLASH_TOKEN, $sanitized);
        self::assertStringNotContainsString('sk_live', $sanitized);
        self::assertStringNotContainsString('9f', $sanitized);
        self::assertSame('/checkout/pay/' . SensitiveParamRedactor::REDACTED, $sanitized);
    }

    // --- Base URL -------------------------------------------------------------------

    public function testBaseUrlIsStrippedBeforeMatchingAndRestoredAfter(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        self::assertSame(
            '/api/checkout/pay/' . SensitiveParamRedactor::REDACTED,
            SensitiveParamRedactor::sanitizePath('/api/checkout/pay/' . self::TOKEN, '/api')
        );
        // Trailing slash on the base URL is tolerated.
        self::assertSame(
            '/api/checkout/pay/' . SensitiveParamRedactor::REDACTED,
            SensitiveParamRedactor::sanitizePath('/api/checkout/pay/' . self::TOKEN, '/api/')
        );
    }

    public function testBaseUrlStrippingStillHonoursRouterNormalization(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        $sanitized = SensitiveParamRedactor::sanitizePath('/api/checkout%2Fpay/' . self::TOKEN, '/api');

        self::assertIsString($sanitized);
        self::assertStringNotContainsString(self::TOKEN, $sanitized);
    }

    public function testBaseUrlMustBeAWholeSegmentPrefix(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        // '/api' must not swallow '/apifoo'.
        self::assertSame(
            '/apifoo/checkout/pay/x',
            SensitiveParamRedactor::sanitizePath('/apifoo/checkout/pay/x', '/api')
        );
    }

    public function testSanitizeUrlAcceptsTheBaseUrl(): void
    {
        SensitiveParamRedactor::configureSensitivePaths(['/checkout/pay/{token}']);

        $sanitized = SensitiveParamRedactor::sanitizeUrl(
            '/api/checkout/pay/' . self::TOKEN . '?keep=1',
            '/api'
        );

        self::assertIsString($sanitized);
        self::assertStringNotContainsString(self::TOKEN, $sanitized);
        self::assertSame('/api/checkout/pay/' . SensitiveParamRedactor::REDACTED . '?keep=1', $sanitized);
    }
}
