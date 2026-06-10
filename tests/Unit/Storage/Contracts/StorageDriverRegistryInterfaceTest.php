<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Storage\Contracts;

use Glueful\Storage\Contracts\StorageDriverRegistryInterface;
use PHPUnit\Framework\TestCase;

final class StorageDriverRegistryInterfaceTest extends TestCase
{
    public function testContractExposesRegisterHasGet(): void
    {
        $this->assertTrue(interface_exists(StorageDriverRegistryInterface::class));

        foreach (['register', 'has', 'get'] as $method) {
            $this->assertTrue(
                method_exists(StorageDriverRegistryInterface::class, $method),
                "Missing contract method: {$method}"
            );
        }
    }
}
