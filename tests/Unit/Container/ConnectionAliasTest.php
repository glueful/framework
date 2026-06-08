<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Container;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Bootstrap\ContainerFactory;
use Glueful\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * The core container registers the connection under the string id 'database'.
 * The documented accessors db($ctx) and app($ctx, Connection::class) both resolve
 * the CONCRETE class id Connection::class, which must therefore also be bound —
 * as an alias to the same shared 'database' instance (mirroring the existing
 * QueryBuilder::class / SchemaBuilderInterface::class aliases).
 *
 * Regression: without the alias, db()/app($ctx, Connection::class) throw
 * "Service 'Glueful\Database\Connection' not found" in a real app (the in-memory
 * test harnesses that bound Connection::class themselves had masked it).
 */
final class ConnectionAliasTest extends TestCase
{
    private function coreContainer(): \Psr\Container\ContainerInterface
    {
        $context = ApplicationContext::forTesting(dirname(__DIR__, 3));
        return ContainerFactory::create($context, false);
    }

    public function test_connection_class_is_bound(): void
    {
        $container = $this->coreContainer();

        $this->assertTrue(
            $container->has(Connection::class),
            'Connection::class must be bound so db()/app($ctx, Connection::class) resolve.'
        );
    }

    public function test_connection_class_aliases_the_same_database_instance(): void
    {
        $container = $this->coreContainer();

        $viaClass = $container->get(Connection::class);
        $viaId = $container->get('database');

        $this->assertInstanceOf(Connection::class, $viaClass);
        $this->assertSame(
            $viaId,
            $viaClass,
            'Connection::class must resolve to the same shared instance as the \'database\' service.'
        );
    }
}
