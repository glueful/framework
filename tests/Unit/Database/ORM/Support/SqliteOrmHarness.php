<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\ORM\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Builds a real in-memory SQLite Connection behind an ApplicationContext whose container
 * resolves `database` (and any extra services a test registers), so ORM models can run
 * real queries via Model::query($context) / Model::create($context, ...).
 */
trait SqliteOrmHarness
{
    protected Connection $connection;
    protected ApplicationContext $context;

    /** @var array<string, mixed> */
    protected array $services = [];

    protected function bootSqliteOrm(): void
    {
        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);

        $this->context = new ApplicationContext(sys_get_temp_dir() . '/glueful_orm_' . uniqid(), 'testing');
        $this->services = ['database' => $this->connection];

        $services = &$this->services;
        $this->context->setContainer(new class ($services) implements ContainerInterface {
            /** @param array<string, mixed> $services */
            public function __construct(private array &$services)
            {
            }

            public function get(string $id): mixed
            {
                if (!array_key_exists($id, $this->services)) {
                    throw new class ("Service {$id} not found") extends \RuntimeException implements
                        NotFoundExceptionInterface {
                    };
                }

                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
        });
    }
}
