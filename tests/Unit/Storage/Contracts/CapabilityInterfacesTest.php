<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Storage\Contracts;

use Glueful\Storage\Contracts\NativeSignedUrlProviderInterface;
use Glueful\Storage\Contracts\StorageHealthCheckInterface;
use PHPUnit\Framework\TestCase;

final class CapabilityInterfacesTest extends TestCase
{
    public function testNativeSignedUrlProviderContract(): void
    {
        $this->assertTrue(interface_exists(NativeSignedUrlProviderInterface::class));
        $this->assertTrue(method_exists(NativeSignedUrlProviderInterface::class, 'temporaryUrl'));
    }

    public function testHealthCheckContract(): void
    {
        $this->assertTrue(interface_exists(StorageHealthCheckInterface::class));
        $this->assertTrue(method_exists(StorageHealthCheckInterface::class, 'check'));
    }
}
