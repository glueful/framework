<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Container\Loader;

use Glueful\Container\Loader\DefaultServicesLoader;
use Glueful\Container\Loader\ServicesLoader;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Container\Autowire\AutowireDefinition;
use PHPUnit\Framework\TestCase;

final class DefaultServicesLoaderTest extends TestCase
{
    private ServicesLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new DefaultServicesLoader();
    }

    public function testSimpleClassWithRefs(): void
    {
        $defs = $this->loader->load([
            \stdClass::class => [
                'class' => \stdClass::class,
                'shared' => true,
                'arguments' => ['@db', '@cache'],
            ],
        ], 'DemoProvider', false);

        $this->assertArrayHasKey(\stdClass::class, $defs);
        $this->assertInstanceOf(FactoryDefinition::class, $defs[\stdClass::class]);
    }

    public function testFactoryArray(): void
    {
        $defs = $this->loader->load([
            'blog.client' => [
                'class' => \stdClass::class,
                'factory' => ['@http.client', 'forBlog'],
                'shared' => true,
            ],
        ]);
        $this->assertArrayHasKey('blog.client', $defs);
        $this->assertInstanceOf(FactoryDefinition::class, $defs['blog.client']);
    }

    public function testAutowireShortcut(): void
    {
        $defs = $this->loader->load([
            \ArrayObject::class => [
                'autowire' => true,
                'shared' => true,
            ],
        ]);
        $this->assertInstanceOf(AutowireDefinition::class, $defs[\ArrayObject::class]);
    }

    public function testShorthands(): void
    {
        $defs = $this->loader->load([
            'mailer' => [
                'class' => \stdClass::class,
                'singleton' => true,
                'arguments' => ['@transport'],
            ],
            \ArrayObject::class => [
                'autowire' => true,
                'bind' => false,
            ],
        ]);

        $this->assertArrayHasKey('mailer', $defs);
        $this->assertArrayHasKey(\ArrayObject::class, $defs);
    }

    public function testProdRejectsClosureFactories(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->loader->load([
            'bad' => [
                'class' => \stdClass::class,
                'factory' => function () {
                },
            ],
        ], 'DemoProvider', true);
    }
}
