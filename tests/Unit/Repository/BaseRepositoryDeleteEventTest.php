<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Repository;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Events\Database\EntityDeletedEvent;
use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use Glueful\Repository\BaseRepository;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Verifies BaseRepository::delete() dispatches exactly one EntityDeletedEvent
 * carrying the pre-delete record, and dispatches none for a missing uuid.
 */
final class BaseRepositoryDeleteEventTest extends TestCase
{
    private Connection $connection;
    private ApplicationContext $context;
    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->dispatched = [];

        // Ensure this test owns its own in-memory SQLite PDO rather than reusing a
        // process-static one cached by the bootstrap or a prior test.
        $this->resetConnectionInstances();

        // Build the capturing context first, then bind the Connection to it. BaseRepository
        // rebuilds any context-less shared connection, so the connection must carry the
        // context for the repository to reuse our seeded in-memory PDO.
        $this->context = $this->makeContext();

        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ], $this->context);

        $pdo = $this->connection->getPDO();
        $pdo->exec('CREATE TABLE posts (uuid TEXT PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO posts (uuid, name) VALUES ('post-1', 'First Post')");
    }

    protected function tearDown(): void
    {
        // Reset the BaseRepository shared connection static to avoid cross-test leakage.
        $ref = new \ReflectionProperty(BaseRepository::class, 'sharedConnection');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $this->resetConnectionInstances();
    }

    private function resetConnectionInstances(): void
    {
        $ref = new \ReflectionProperty(Connection::class, 'instances');
        $ref->setAccessible(true);
        $ref->setValue(null, []);
    }

    private function makeContext(): ApplicationContext
    {
        $provider = new ListenerProvider();
        $dispatcher = new EventDispatcher($provider);
        $eventService = new EventService($dispatcher, $provider);

        // Capture every EntityDeletedEvent that flows through the dispatcher.
        $eventService->addListener(EntityDeletedEvent::class, function (object $event): void {
            $this->dispatched[] = $event;
        });

        $container = new class ($eventService) implements ContainerInterface {
            public function __construct(private EventService $eventService)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === EventService::class) {
                    return $this->eventService;
                }
                throw new \RuntimeException("Unexpected service request: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === EventService::class;
            }
        };

        $context = ApplicationContext::forTesting(dirname(__DIR__, 3));
        $context->setContainer($container);

        return $context;
    }

    public function test_delete_dispatches_entity_deleted_event_with_pre_delete_data(): void
    {
        $repo = new TestPostRepository($this->connection, $this->context);

        $result = $repo->delete('post-1');

        $this->assertTrue($result);
        $this->assertCount(1, $this->dispatched);

        $event = $this->dispatched[0];
        $this->assertInstanceOf(EntityDeletedEvent::class, $event);
        $this->assertSame('posts', $event->getTable());
        $this->assertSame('post-1', $event->getEntityId());

        $original = $event->getOriginalData();
        $this->assertIsArray($original);
        $this->assertSame('First Post', $original['name']);
        $this->assertSame('delete', $event->getMetadata('operation'));
        $this->assertSame('post-1', $event->getMetadata('entity_id'));
        $this->assertSame(1, $event->getMetadata('affected_rows'));
    }

    public function test_delete_of_missing_uuid_dispatches_no_event(): void
    {
        $repo = new TestPostRepository($this->connection, $this->context);

        $result = $repo->delete('does-not-exist');

        $this->assertFalse($result);
        $this->assertCount(0, $this->dispatched);
    }
}

/**
 * Minimal in-suite repository over the temp `posts` table.
 */
final class TestPostRepository extends BaseRepository
{
    public function getTableName(): string
    {
        return 'posts';
    }
}
