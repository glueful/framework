<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Exceptions;

use Glueful\Database\Exceptions\ConnectionLostException;
use Glueful\Database\Exceptions\ConstraintViolationException;
use Glueful\Database\Exceptions\DatabaseException;
use Glueful\Database\Exceptions\DatabaseExceptionInterface;
use Glueful\Database\Exceptions\DeadlockException;
use Glueful\Database\Exceptions\ForeignKeyConstraintViolationException;
use Glueful\Database\Exceptions\LockContentionException;
use Glueful\Database\Exceptions\NotNullConstraintViolationException;
use Glueful\Database\Exceptions\RetryableTransactionFailureInterface;
use Glueful\Database\Exceptions\SerializationFailureException;
use Glueful\Database\Exceptions\TransientFailureInterface;
use Glueful\Database\Exceptions\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DatabaseExceptionTest extends TestCase
{
    /**
     * Build a synthetic PDOException carrying real driver error shapes.
     * PDO sets string SQLSTATE codes on the protected $code property, which
     * plain construction cannot. An anonymous PDOException subclass can assign
     * that inherited protected property without trying to bind a closure to the
     * internal \Exception class scope (which PHP rejects).
     *
     * @param array{0: string, 1?: int|string|null, 2?: string}|null $errorInfo
     */
    private function pdoException(
        string $message,
        ?array $errorInfo,
        int|string|null $code = null
    ): \PDOException {
        return new class ($message, $errorInfo, $code) extends \PDOException {
            /**
             * @param array{0: string, 1?: int|string|null, 2?: string}|null $errorInfo
             */
            public function __construct(
                string $message,
                ?array $errorInfo,
                int|string|null $code
            ) {
                parent::__construct($message);
                $this->errorInfo = $errorInfo;

                if ($code !== null) {
                    $this->code = $code;
                }
            }
        };
    }

    /**
     * @return iterable<string, array{class: class-string<DatabaseException>}>
     */
    public static function concreteExceptionClasses(): iterable
    {
        yield 'generic database failure' => ['class' => DatabaseException::class];
        yield 'generic constraint violation' => ['class' => ConstraintViolationException::class];
        yield 'unique constraint violation' => ['class' => UniqueConstraintViolationException::class];
        yield 'foreign-key constraint violation' => [
            'class' => ForeignKeyConstraintViolationException::class,
        ];
        yield 'not-null constraint violation' => ['class' => NotNullConstraintViolationException::class];
        yield 'deadlock' => ['class' => DeadlockException::class];
        yield 'serialization failure' => ['class' => SerializationFailureException::class];
        yield 'lock contention' => ['class' => LockContentionException::class];
        yield 'connection lost' => ['class' => ConnectionLostException::class];
    }

    /** @param class-string<DatabaseException> $class */
    #[Test]
    #[DataProvider('concreteExceptionClasses')]
    public function fromPdoPreservesAllOriginalState(string $class): void
    {
        $original = $this->pdoException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'a@b.c'",
            ['23000', 1062, "Duplicate entry 'a@b.c' for key 'users.email'"],
            '23000'
        );

        $exception = $class::fromPdo($original, 'mysql');

        $this->assertSame($class, get_class($exception));
        $this->assertInstanceOf(DatabaseExceptionInterface::class, $exception);
        $this->assertInstanceOf(\PDOException::class, $exception);
        $this->assertSame($original->getMessage(), $exception->getMessage());
        $this->assertSame($original->getCode(), $exception->getCode());
        $this->assertSame($original->errorInfo, $exception->errorInfo);
        $this->assertSame($original, $exception->getPrevious());
    }

    #[Test]
    public function fromPdoExposesParsedAccessors(): void
    {
        $original = $this->pdoException(
            'SQLSTATE[40P01]: deadlock detected',
            ['40P01', 7, 'deadlock detected'],
            '40P01'
        );

        $exception = DeadlockException::fromPdo($original, 'pgsql');

        $this->assertSame('40P01', $exception->sqlState());
        $this->assertSame(7, $exception->driverCode());
        $this->assertSame('pgsql', $exception->driver());
    }

    #[Test]
    public function extractSqlStatePrefersErrorInfoOverCode(): void
    {
        $e = $this->pdoException('m', ['23505', 0, 'x'], 'HY000');
        $this->assertSame('23505', DatabaseException::extractSqlState($e));
    }

    #[Test]
    public function extractSqlStateFallsBackToStringCodeThatResemblesSqlState(): void
    {
        $e = $this->pdoException('m', null, '40001');
        $this->assertSame('40001', DatabaseException::extractSqlState($e));
    }

    #[Test]
    public function extractSqlStateReturnsNullForNonSqlStateShapes(): void
    {
        $this->assertNull(DatabaseException::extractSqlState($this->pdoException('m', null, 0)));
        $this->assertNull(DatabaseException::extractSqlState($this->pdoException('m', null, 'oops')));
        $this->assertNull(DatabaseException::extractSqlState($this->pdoException('m', null)));
    }

    #[Test]
    public function extractDriverCodeReadsErrorInfoIndexOne(): void
    {
        $this->assertSame(1213, DatabaseException::extractDriverCode(
            $this->pdoException('m', ['40001', 1213, 'Deadlock found'])
        ));
        $this->assertNull(DatabaseException::extractDriverCode($this->pdoException('m', null)));
    }

    #[Test]
    public function hierarchyAndMarkersAreExactlyAsSpecified(): void
    {
        $raw = $this->pdoException('m', ['HY000', 0, 'x']);

        foreach (
            [
                UniqueConstraintViolationException::class,
                ForeignKeyConstraintViolationException::class,
                NotNullConstraintViolationException::class,
            ] as $constraintClass
        ) {
            $e = $constraintClass::fromPdo($raw, 'sqlite');
            $this->assertInstanceOf(ConstraintViolationException::class, $e);
            $this->assertNotInstanceOf(TransientFailureInterface::class, $e);
        }

        foreach (
            [
                DeadlockException::class,
                SerializationFailureException::class,
                LockContentionException::class,
            ] as $retryableClass
        ) {
            $e = $retryableClass::fromPdo($raw, 'sqlite');
            $this->assertInstanceOf(RetryableTransactionFailureInterface::class, $e);
        }

        $lost = ConnectionLostException::fromPdo($raw, 'mysql');
        $this->assertInstanceOf(TransientFailureInterface::class, $lost);
        $this->assertNotInstanceOf(RetryableTransactionFailureInterface::class, $lost);
    }
}
