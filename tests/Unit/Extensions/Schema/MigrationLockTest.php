<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions\Schema;

use Glueful\Database\Connection;
use Glueful\Database\Exceptions\LockContentionException;
use Glueful\Extensions\Schema\FileMigrationLock;
use Glueful\Extensions\Schema\MigrationLockFactory;
use Glueful\Extensions\Schema\MigrationLockInterface;
use Glueful\Extensions\Schema\MysqlNamedMigrationLock;
use Glueful\Extensions\Schema\PgsqlAdvisoryMigrationLock;
use Glueful\Installer\DatabaseConfig;
use PHPUnit\Framework\TestCase;

final class MigrationLockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/glueful-lock-' . uniqid('', true);
        mkdir($this->dir, 0777, true);
    }

    public function testAcquisitionOrderIsDeterministicallySorted(): void
    {
        $order = [];
        $lock = new class ($this->dir, $order) extends FileMigrationLock {
            /** @param list<string> $order */
            public function __construct(string $dir, private array &$order)
            {
                parent::__construct($dir);
            }

            protected function tryAcquire(string $source): bool
            {
                $this->order[] = $source;
                return parent::tryAcquire($source);
            }
        };

        $handle = $lock->acquireAll(['zeta', 'alpha', 'mid']);
        $handle->release();

        self::assertSame(['alpha', 'mid', 'zeta'], $order);
    }

    public function testContentionOnAHeldSourceThrowsWithinTheBoundedWait(): void
    {
        $holder = new FileMigrationLock($this->dir);
        $held = $holder->acquireAll(['s1']);

        $contender = new FileMigrationLock($this->dir);
        $start = microtime(true);
        try {
            $contender->acquireAll(['s1'], waitSeconds: 1);
            self::fail('expected LockContentionException');
        } catch (LockContentionException) {
            $elapsed = microtime(true) - $start;
            self::assertGreaterThanOrEqual(0.9, $elapsed, 'must actually wait the bounded window');
            self::assertLessThan(3.0, $elapsed, 'must not block past the bounded window');
        } finally {
            $held->release();
        }
    }

    public function testPartialAcquisitionIsRolledBackOnContention(): void
    {
        $holder = new FileMigrationLock($this->dir);
        $held = $holder->acquireAll(['bbb']); // will block the second source in sorted order

        $contender = new FileMigrationLock($this->dir);
        try {
            $contender->acquireAll(['aaa', 'bbb'], waitSeconds: 1);
            self::fail('expected LockContentionException');
        } catch (LockContentionException) {
            // 'aaa' was acquired first (sorted) and must have been RELEASED on failure.
            $third = new FileMigrationLock($this->dir);
            $probe = $third->acquireAll(['aaa'], waitSeconds: 1);
            $probe->release();
            self::assertTrue(true, 'aaa was free — partial custody rolled back');
        } finally {
            $held->release();
        }
    }

    public function testDisjointSourcesDoNotContendAndReleaseFrees(): void
    {
        $a = new FileMigrationLock($this->dir);
        $b = new FileMigrationLock($this->dir);
        $ha = $a->acquireAll(['one']);
        $hb = $b->acquireAll(['two'], waitSeconds: 1);
        $hb->release();
        $ha->release();

        // After release, the same source is immediately acquirable again.
        $again = $b->acquireAll(['one'], waitSeconds: 1);
        $again->release();
        self::assertTrue(true);
    }

    public function testInterfaceExposesNoTtlOrExpiry(): void
    {
        $methods = array_map(
            static fn(\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(MigrationLockInterface::class))->getMethods()
        );
        self::assertSame(['acquireAll'], $methods, 'custody has no TTL surface — release() is the only exit');
    }

    public function testFactorySelectsTheImplementationByDriver(): void
    {
        $config = new DatabaseConfig('sqlite', database: $this->dir . '/db.sqlite');
        $connection = new Connection($config->toConnectionConfig());

        self::assertInstanceOf(
            FileMigrationLock::class,
            MigrationLockFactory::forDriver('sqlite', $connection, $this->dir)
        );
        self::assertInstanceOf(
            PgsqlAdvisoryMigrationLock::class,
            MigrationLockFactory::forDriver('pgsql', $connection, $this->dir)
        );
        self::assertInstanceOf(
            MysqlNamedMigrationLock::class,
            MigrationLockFactory::forDriver('mysql', $connection, $this->dir)
        );
    }
}
