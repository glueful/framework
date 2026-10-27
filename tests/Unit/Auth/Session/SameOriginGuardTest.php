<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth\Session;

use Glueful\Auth\Session\SameOriginGuard;
use Glueful\Http\Cors;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SameOriginGuardTest extends TestCase
{
    public function testFetchMetadataSameOriginIsAccepted(): void
    {
        $request = Request::create('https://app.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Sec-Fetch-Site', 'same-origin');

        self::assertTrue((new SameOriginGuard())->isSameOrigin($request));
    }

    public function testFetchMetadataCrossSiteIsRejected(): void
    {
        $request = Request::create('https://app.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Sec-Fetch-Site', 'cross-site');
        $request->headers->set('Origin', 'https://app.example.test');

        // Fetch metadata is authoritative when present — a matching Origin cannot rescue it.
        self::assertFalse((new SameOriginGuard())->isSameOrigin($request));
    }

    public function testSameSiteIsRejectedBecauseASiblingSubdomainIsNotThisOrigin(): void
    {
        $request = Request::create('https://app.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Sec-Fetch-Site', 'same-site');

        self::assertFalse((new SameOriginGuard())->isSameOrigin($request));
    }

    public function testSameSiteIsAcceptedForAnAllowedCredentialedCorsOrigin(): void
    {
        // SPA on app.example.test calling the API on api.example.test.
        $request = Request::create('https://api.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Sec-Fetch-Site', 'same-site');
        $request->headers->set('Origin', 'https://app.example.test');

        $guard = new SameOriginGuard($this->cors(['https://app.example.test'], true));

        self::assertTrue($guard->isSameOrigin($request));
    }

    public function testSameSiteMatchingACorsPatternIsAccepted(): void
    {
        $request = Request::create('https://api.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Sec-Fetch-Site', 'same-site');
        $request->headers->set('Origin', 'https://admin.example.test');

        $cors = new Cors([
            'allowedOrigins' => [],
            'allowedOriginPatterns' => ['#^https://[a-z]+\.example\.test$#'],
            'supportsCredentials' => true,
        ]);

        self::assertTrue((new SameOriginGuard($cors))->isSameOrigin($request));
    }

    public function testSameSiteIsRejectedWhenCorsCredentialsAreOff(): void
    {
        $request = Request::create('https://api.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Sec-Fetch-Site', 'same-site');
        $request->headers->set('Origin', 'https://app.example.test');

        $guard = new SameOriginGuard($this->cors(['https://app.example.test'], false));

        self::assertFalse($guard->isSameOrigin($request));
    }

    public function testSameSiteIsRejectedForAnOriginOutsideTheCorsList(): void
    {
        $request = Request::create('https://api.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Sec-Fetch-Site', 'same-site');
        $request->headers->set('Origin', 'https://compromised.example.test');

        $guard = new SameOriginGuard($this->cors(['https://app.example.test'], true));

        self::assertFalse($guard->isSameOrigin($request));
    }

    public function testSameSiteIsRejectedForAWildcardCorsList(): void
    {
        // Wildcard + credentials is never honoured by Cors, so it can't authorise a sibling.
        $request = Request::create('https://api.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Sec-Fetch-Site', 'same-site');
        $request->headers->set('Origin', 'https://app.example.test');

        $guard = new SameOriginGuard($this->cors(['*'], true));

        self::assertFalse($guard->isSameOrigin($request));
    }

    public function testSameSiteIsRejectedUnderTheDevelopmentAllowAllOverride(): void
    {
        $request = Request::create('https://api.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Sec-Fetch-Site', 'same-site');
        $request->headers->set('Origin', 'https://app.example.test');

        $cors = new Cors(['allowedOrigins' => [], 'developmentAllowAll' => true, 'supportsCredentials' => true]);

        self::assertFalse((new SameOriginGuard($cors))->isSameOrigin($request));
    }

    public function testSameSiteWithoutAnOriginHeaderIsRejected(): void
    {
        $request = Request::create('https://api.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Sec-Fetch-Site', 'same-site');

        $guard = new SameOriginGuard($this->cors(['https://app.example.test'], true));

        self::assertFalse($guard->isSameOrigin($request));
    }

    public function testCrossSiteIsRejectedEvenForAnAllowedCredentialedOrigin(): void
    {
        $request = Request::create('https://api.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Sec-Fetch-Site', 'cross-site');
        $request->headers->set('Origin', 'https://app.example.test');

        $guard = new SameOriginGuard($this->cors(['https://app.example.test'], true));

        self::assertFalse($guard->isSameOrigin($request));
    }

    public function testAnAllowedOriginWithoutFetchMetadataIsStillRejected(): void
    {
        // Without Sec-Fetch-Site nothing proves the caller is same-site: behaviour unchanged.
        $request = Request::create('https://api.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Origin', 'https://app.example.test');

        $guard = new SameOriginGuard($this->cors(['https://app.example.test'], true));

        self::assertFalse($guard->isSameOrigin($request));
    }

    /** @param array<string> $origins */
    private function cors(array $origins, bool $credentials): Cors
    {
        return new Cors([
            'allowedOrigins' => $origins,
            'allowedOriginPatterns' => [],
            'developmentAllowAll' => false,
            'supportsCredentials' => $credentials,
        ]);
    }

    public function testExactOriginMatchIsAcceptedWhenFetchMetadataIsAbsent(): void
    {
        $request = Request::create('https://app.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Origin', 'https://app.example.test');

        self::assertTrue((new SameOriginGuard())->isSameOrigin($request));
    }

    public function testAForeignOriginIsRejected(): void
    {
        $request = Request::create('https://app.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Origin', 'https://evil.example.test');

        self::assertFalse((new SameOriginGuard())->isSameOrigin($request));
    }

    public function testASchemeMismatchIsRejected(): void
    {
        $request = Request::create('https://app.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Origin', 'http://app.example.test');

        self::assertFalse((new SameOriginGuard())->isSameOrigin($request));
    }

    public function testARequestWithNeitherHeaderIsRejected(): void
    {
        // Browsers send one or the other on a POST; anything else should use the bearer
        // refresh endpoint rather than the cookie one.
        $request = Request::create('https://app.example.test/auth/session/refresh', 'POST');

        self::assertFalse((new SameOriginGuard())->isSameOrigin($request));
    }
}
