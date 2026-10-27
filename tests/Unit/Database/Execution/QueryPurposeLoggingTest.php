<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Execution;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\QueryLogger;
use Glueful\Events\Database\QueryExecutedEvent;
use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * A builder's withPurpose() must reach the query log and QueryExecutedEvent, and the builder's
 * logger must carry the connection's context so the event is dispatched at all — but only when
 * something listens, so an unobserved query pays nothing for it.
 */
final class QueryPurposeLoggingTest extends TestCase
{
    private EventService $events;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        $provider = new ListenerProvider();
        $this->events = new EventService(new EventDispatcher($provider), $provider);
        $events = $this->events;

        $this->context = new ApplicationContext(sys_get_temp_dir());
        $this->context->setContainer(new class ($events) implements ContainerInterface {
            public function __construct(private EventService $events)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === EventService::class) {
                    return $this->events;
                }
                throw new class ("No entry: {$id}") extends \RuntimeException implements
                    \Psr\Container\NotFoundExceptionInterface {
                };
            }

            public function has(string $id): bool
            {
                return $id === EventService::class;
            }
        });
    }

    private function connection(): Connection
    {
        $conn = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ], $this->context);
        $conn->getPDO()->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');
        $conn->getPDO()->exec("INSERT INTO widgets (name) VALUES ('a'), ('b')");

        return $conn;
    }

    public function testAPurposeSetOnACloneDoesNotTagTheOriginal(): void
    {
        $seen = [];
        $this->events->addListener(QueryExecutedEvent::class, function (QueryExecutedEvent $e) use (&$seen): void {
            $seen[] = $e->getMetadata('purpose');
        });

        $original = $this->connection()->table('widgets');
        $original->clone()->withPurpose('export')->get();
        $original->get();

        self::assertSame('export', $seen[0], 'the clone carries its purpose');
        self::assertNotSame('export', $seen[1], 'the original shared the clone\'s executor');
    }

    public function testBuilderQueriesDispatchQueryExecutedEventCarryingThePurpose(): void
    {
        $seen = [];
        $this->events->addListener(QueryExecutedEvent::class, function (QueryExecutedEvent $e) use (&$seen): void {
            $seen[] = $e;
        });

        $this->connection()->table('widgets')->withPurpose('billing-report')->get();

        self::assertCount(1, $seen, 'the builder logger has the context, so the event fires');
        self::assertStringContainsString('widgets', $seen[0]->getSql());
        self::assertSame('billing-report', $seen[0]->getMetadata('purpose'));
    }

    public function testPurposeAppliesToEveryQueryTheBuilderRuns(): void
    {
        $purposes = [];
        $this->events->addListener(QueryExecutedEvent::class, function (QueryExecutedEvent $e) use (&$purposes): void {
            $purposes[] = $e->getMetadata('purpose');
        });

        $qb = $this->connection()->table('widgets')->withPurpose('audit');
        $qb->get();
        $qb->count();

        self::assertSame(['audit', 'audit'], $purposes);
    }

    public function testPurposeReachesTheQueryLogInDebugMode(): void
    {
        $logger = new QueryLogger(null, $this->context);
        $logger->configure(true, true);

        $logger->logQuery('SELECT 1', [], microtime(true), null, 'nightly-sync');

        self::assertSame('nightly-sync', $logger->getQueryLog()[0]['purpose']);
    }

    public function testNoListenersMeansNoDispatch(): void
    {
        $dispatched = false;
        $provider = new ListenerProvider();
        $dispatcher = new class ($dispatched) implements \Psr\EventDispatcher\EventDispatcherInterface {
            public function __construct(private bool &$flag)
            {
            }

            public function dispatch(object $event): object
            {
                $this->flag = true;
                return $event;
            }
        };
        $events = new EventService($dispatcher, $provider);
        $context = new ApplicationContext(sys_get_temp_dir());
        $context->setContainer(new class ($events) implements ContainerInterface {
            public function __construct(private EventService $events)
            {
            }

            public function get(string $id): mixed
            {
                return $this->events;
            }

            public function has(string $id): bool
            {
                return $id === EventService::class;
            }
        });

        $logger = new QueryLogger(null, $context);
        $logger->configure(true, true);
        $logger->logQuery('SELECT 1', [], microtime(true), null, 'x');

        self::assertFalse($dispatched, 'no QueryExecutedEvent listeners: the event is not built or dispatched');
    }
}
