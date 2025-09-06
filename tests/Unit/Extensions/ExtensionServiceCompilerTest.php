<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use PHPUnit\Framework\TestCase;
use Glueful\Extensions\ExtensionServiceCompiler;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class ExtensionServiceCompilerTest extends TestCase
{
    private ContainerBuilder $container;
    private ExtensionServiceCompiler $compiler;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
        $this->compiler = new ExtensionServiceCompiler($this->container);
    }

    public function testRegisterSimpleService(): void
    {
        $serviceDefs = [
            'test.service' => [
                'class' => 'TestService',
                'shared' => true,
            ]
        ];

        $this->compiler->register($serviceDefs, 'TestProvider');

        $this->assertTrue($this->container->hasDefinition('test.service'));
        $definition = $this->container->getDefinition('test.service');
        $this->assertEquals('TestService', $definition->getClass());
        $this->assertTrue($definition->isShared());
        $this->assertFalse($definition->isPublic()); // Default to private
    }

    public function testRegisterServiceWithArguments(): void
    {
        $serviceDefs = [
            'dependency.service' => [
                'class' => 'DependencyService',
            ],
            'test.service' => [
                'class' => 'TestService',
                'arguments' => ['@dependency.service', 'scalar_value'],
            ]
        ];

        $this->compiler->register($serviceDefs, 'TestProvider');

        $definition = $this->container->getDefinition('test.service');
        $arguments = $definition->getArguments();

        $this->assertCount(2, $arguments);
        $this->assertInstanceOf(Reference::class, $arguments[0]);
        $this->assertEquals('dependency.service', (string) $arguments[0]);
        $this->assertEquals('scalar_value', $arguments[1]);
    }

    public function testRegisterServiceWithFactory(): void
    {
        $serviceDefs = [
            'factory.service' => [
                'class' => 'FactoryService',
            ],
            'test.service' => [
                'class' => 'TestService',
                'factory' => ['@factory.service', 'createService'],
            ]
        ];

        $this->compiler->register($serviceDefs, 'TestProvider');

        $definition = $this->container->getDefinition('test.service');
        $factory = $definition->getFactory();

        $this->assertIsArray($factory);
        $this->assertInstanceOf(Reference::class, $factory[0]);
        $this->assertEquals('factory.service', (string) $factory[0]);
        $this->assertEquals('createService', $factory[1]);
    }

    public function testRegisterServiceWithTags(): void
    {
        $serviceDefs = [
            'test.service' => [
                'class' => 'TestService',
                'tags' => [
                    'console.command',
                    ['name' => 'event.subscriber', 'priority' => 100]
                ]
            ]
        ];

        $this->compiler->register($serviceDefs, 'TestProvider');

        $definition = $this->container->getDefinition('test.service');
        $tags = $definition->getTags();

        $this->assertArrayHasKey('console.command', $tags);
        $this->assertArrayHasKey('event.subscriber', $tags);
        $this->assertEquals([['priority' => 100]], $tags['event.subscriber']);
    }

    public function testRegisterServiceWithAlias(): void
    {
        $serviceDefs = [
            'test.service' => [
                'class' => 'TestService',
                'alias' => ['TestServiceInterface', 'test.alias']
            ]
        ];

        $this->compiler->register($serviceDefs, 'TestProvider');

        $this->assertTrue($this->container->hasAlias('TestServiceInterface'));
        $this->assertTrue($this->container->hasAlias('test.alias'));
        $this->assertEquals('test.service', (string) $this->container->getAlias('TestServiceInterface'));
        $this->assertEquals('test.service', (string) $this->container->getAlias('test.alias'));
    }

    public function testRegisterPublicService(): void
    {
        $serviceDefs = [
            'test.service' => [
                'class' => 'TestService',
                'public' => true,
            ]
        ];

        $this->compiler->register($serviceDefs, 'TestProvider');

        $definition = $this->container->getDefinition('test.service');
        $this->assertTrue($definition->isPublic());
    }

    public function testCollisionDetectionFirstWins(): void
    {
        // Register first service
        $firstServiceDefs = [
            'test.service' => [
                'class' => 'FirstService',
            ]
        ];
        $this->compiler->register($firstServiceDefs, 'FirstProvider');

        // Try to register conflicting service
        $secondServiceDefs = [
            'test.service' => [
                'class' => 'SecondService',
            ]
        ];

        // Capture error log output using output buffering and custom log file
        $logFile = tempnam(sys_get_temp_dir(), 'test_error_log');
        $originalLogFile = ini_get('error_log');
        ini_set('error_log', $logFile);

        $this->compiler->register($secondServiceDefs, 'SecondProvider');

        // Restore original error log setting
        ini_set('error_log', $originalLogFile);

        // First definition should win
        $definition = $this->container->getDefinition('test.service');
        $this->assertEquals('FirstService', $definition->getClass());

        // Should have logged collision
        $logContents = file_get_contents($logFile);
        unlink($logFile);
        $this->assertStringContainsString('Service collision', $logContents);
    }

    public function testDecoratorService(): void
    {
        $serviceDefs = [
            'decorator.service' => [
                'class' => 'DecoratorService',
                'decorate' => [
                    'id' => 'original.service',
                    'priority' => 100,
                    'inner' => 'decorator.service.inner'
                ]
            ]
        ];

        $this->compiler->register($serviceDefs, 'TestProvider');

        $definition = $this->container->getDefinition('decorator.service');
        $decoratedService = $definition->getDecoratedService();

        $this->assertNotNull($decoratedService);
        $this->assertEquals('original.service', $decoratedService[0]);
        $this->assertEquals('decorator.service.inner', $decoratedService[1]);
        $this->assertEquals(100, $decoratedService[2]);
    }

    public function testInvalidArgumentReferenceThrowsException(): void
    {
        $serviceDefs = [
            'test.service' => [
                'class' => 'TestService',
                'arguments' => ['@'], // Invalid reference
            ]
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid argument');

        $this->compiler->register($serviceDefs, 'TestProvider');
    }

    public function testClosureFactoryThrowsException(): void
    {
        $serviceDefs = [
            'test.service' => [
                'class' => 'TestService',
                'factory' => function () {
                    return new \stdClass();
                }, // Closures not allowed
            ]
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Closures are not allowed as factories');

        $this->compiler->register($serviceDefs, 'TestProvider');
    }
}
