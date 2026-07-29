<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth\Session;

use Glueful\Auth\Session\SameOriginGuard;
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
