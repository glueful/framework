<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Storage\Contracts;

use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use PHPUnit\Framework\TestCase;

final class StorageDriverFactoryInterfaceTest extends TestCase
{
    public function testContractExposesIdentityConstructionAvailabilityAndFeatures(): void
    {
        $this->assertTrue(interface_exists(StorageDriverFactoryInterface::class));

        foreach (['driver', 'create', 'available', 'features'] as $method) {
            $this->assertTrue(
                method_exists(StorageDriverFactoryInterface::class, $method),
                "Missing contract method: {$method}"
            );
        }
    }
}
