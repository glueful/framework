<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ServiceProvider;
use Glueful\Extensions\OrderedProvider;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class ExtensionManagerTest extends TestCase
{
    private ContainerInterface&MockObject $container;
    private ExtensionManager $manager;

    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->manager = new ExtensionManager($this->container);
    }

    public function testExtensionOrderingStablePriorityWithFifo(): void
    {
        // Create providers with same priority (0) in discovery order
        $providerFirst = new class ($this->container) extends ServiceProvider {
            public function priority(): int
            {
                return 0;
            }
        };
        $providerSecond = new class ($this->container) extends ServiceProvider {
            public function priority(): int
            {
                return 0;
            }
        };
        $providerThird = new class ($this->container) extends ServiceProvider {
            public function priority(): int
            {
                return 0;
            }
        };

        $providers = [
            'First\\Provider' => $providerFirst,
            'Second\\Provider' => $providerSecond,
            'Third\\Provider' => $providerThird,
        ];

        $manager = new ExtensionManager($this->container);
        $reflection = new \ReflectionClass($manager);
        $property = $reflection->getProperty('providers');
        $property->setAccessible(true);
        $property->setValue($manager, $providers);

        // Call sortProviders
        $method = $reflection->getMethod('sortProviders');
        $method->setAccessible(true);
        $method->invoke($manager);

        // Verify FIFO order is preserved for equal priorities
        $sorted = array_keys($property->getValue($manager));
        $this->assertEquals(['First\\Provider', 'Second\\Provider', 'Third\\Provider'], $sorted);
    }

    public function testCircularDependencyFallsBackToPriorityOrder(): void
    {
        // Create providers with circular bootAfter() and different priorities
        $providerA = new class ($this->container) extends ServiceProvider implements OrderedProvider {
            public function priority(): int
            {
                return 1;
            }
            public function bootAfter(): array
            {
                return ['B\\Provider'];
            }
        };

        $providerB = new class ($this->container) extends ServiceProvider implements OrderedProvider {
            public function priority(): int
            {
                return 0;
            }
            // Higher priority (sorts first)
            public function bootAfter(): array
            {
                return ['A\\Provider'];
            }
        };

        $providers = [
            'A\\Provider' => $providerA,
            'B\\Provider' => $providerB,
        ];

        $manager = new ExtensionManager($this->container);
        $reflection = new \ReflectionClass($manager);
        $property = $reflection->getProperty('providers');
        $property->setAccessible(true);
        $property->setValue($manager, $providers);

        // Mock logger to capture warning
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
               ->method('warning')
               ->with($this->stringContains('Circular dependency detected'));

        $this->container->method('has')
                       ->with(LoggerInterface::class)
                       ->willReturn(true);
        $this->container->method('get')
                       ->with(LoggerInterface::class)
                       ->willReturn($logger);

        // Call sortProviders
        $method = $reflection->getMethod('sortProviders');
        $method->setAccessible(true);
        $method->invoke($manager);

        // Verify priority order fallback (B=0, A=1)
        $sorted = array_keys($property->getValue($manager));
        $this->assertEquals(['B\\Provider', 'A\\Provider'], $sorted);
    }

    public function testHasProvider(): void
    {
        $provider = new class ($this->container) extends ServiceProvider {
        };
        $providers = ['Test\\Provider' => $provider];

        $reflection = new \ReflectionClass($this->manager);
        $property = $reflection->getProperty('providers');
        $property->setAccessible(true);
        $property->setValue($this->manager, $providers);

        $this->assertTrue($this->manager->hasProvider('Test\\Provider'));
        $this->assertFalse($this->manager->hasProvider('Missing\\Provider'));
    }

    public function testGetProviders(): void
    {
        $provider = new class ($this->container) extends ServiceProvider {
        };
        $providers = ['Test\\Provider' => $provider];

        $reflection = new \ReflectionClass($this->manager);

        // Set providers
        $property = $reflection->getProperty('providers');
        $property->setAccessible(true);
        $property->setValue($this->manager, $providers);

        // Mark as discovered to prevent auto-discovery
        $discoveredProperty = $reflection->getProperty('discovered');
        $discoveredProperty->setAccessible(true);
        $discoveredProperty->setValue($this->manager, true);

        $result = $this->manager->getProviders();
        $this->assertSame($providers, $result);
    }

    public function testGetSummary(): void
    {
        $summary = $this->manager->getSummary();

        $this->assertIsArray($summary);
        $this->assertArrayHasKey('total_providers', $summary);
        $this->assertArrayHasKey('booted', $summary);
        $this->assertArrayHasKey('cache_used', $summary);

        $this->assertIsInt($summary['total_providers']);
        $this->assertIsBool($summary['booted']);
        $this->assertIsBool($summary['cache_used']);
    }
}
