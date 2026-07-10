<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Uploader;

use Glueful\Uploader\Contracts\BlobPublicUrlProvider;
use Glueful\Uploader\Contracts\BlobRouteAction;
use Glueful\Uploader\Contracts\BlobRouteMiddlewareProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class BlobRouteSeamsTest extends TestCase
{
    public function testActionsCoverEveryBlobEndpoint(): void
    {
        self::assertSame(
            ['upload', 'view', 'info', 'delete', 'sign'],
            array_map(static fn (BlobRouteAction $action): string => $action->value, BlobRouteAction::cases()),
        );
    }

    public function testProviderContractsExposeTheExpectedSignatures(): void
    {
        $middleware = new ReflectionMethod(BlobRouteMiddlewareProvider::class, 'middlewareFor');
        $publicUrl = new ReflectionMethod(BlobPublicUrlProvider::class, 'publicBaseUrl');

        self::assertSame(BlobRouteAction::class, (string) $middleware->getParameters()[0]->getType());
        self::assertSame('array', (string) $middleware->getReturnType());
        self::assertSame('array', (string) $publicUrl->getParameters()[0]->getType());
        self::assertTrue($publicUrl->getReturnType()?->allowsNull());
    }
}
