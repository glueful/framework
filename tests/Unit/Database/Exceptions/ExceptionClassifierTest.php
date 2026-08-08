<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Exceptions;

use Glueful\Database\Exceptions\ConnectionLostException;
use Glueful\Database\Exceptions\ConstraintViolationException;
use Glueful\Database\Exceptions\DatabaseException;
use Glueful\Database\Exceptions\DeadlockException;
use Glueful\Database\Exceptions\ExceptionClassifier;
use Glueful\Database\Exceptions\ForeignKeyConstraintViolationException;
use Glueful\Database\Exceptions\LockContentionException;
use Glueful\Database\Exceptions\NotNullConstraintViolationException;
use Glueful\Database\Exceptions\SerializationFailureException;
use Glueful\Database\Exceptions\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExceptionClassifierTest extends TestCase
{
    private ExceptionClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new ExceptionClassifier();
    }

    /**
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
     * @return iterable<string, array{driver: string, errorInfo: array{0: string, 1?: int|string|null, 2?: string}, expected: class-string<DatabaseException>}>
     */
    public static function classificationCases(): iterable
    {
        // MySQL — vendor codes win; SQLSTATE is often generic or misleading.
        yield 'mysql duplicate entry (23000+1062)' => [
            'driver' => 'mysql', 'errorInfo' => ['23000', 1062, "Duplicate entry"],
            'expected' => UniqueConstraintViolationException::class,
        ];
        yield 'mysql fk parent missing (23000+1452)' => [
            'driver' => 'mysql', 'errorInfo' => ['23000', 1452, 'Cannot add or update a child row'],
            'expected' => ForeignKeyConstraintViolationException::class,
        ];
        yield 'mysql fk child rows (23000+1451)' => [
            'driver' => 'mysql', 'errorInfo' => ['23000', 1451, 'Cannot delete or update a parent row'],
            'expected' => ForeignKeyConstraintViolationException::class,
        ];
        yield 'mysql not null (23000+1048)' => [
            'driver' => 'mysql', 'errorInfo' => ['23000', 1048, "Column 'x' cannot be null"],
            'expected' => NotNullConstraintViolationException::class,
        ];
        yield 'mysql deadlock reported with 40001 (vendor-first is load-bearing)' => [
            'driver' => 'mysql', 'errorInfo' => ['40001', 1213, 'Deadlock found when trying to get lock'],
            'expected' => DeadlockException::class,
        ];
        yield 'mysql lock wait timeout (1205)' => [
            'driver' => 'mysql', 'errorInfo' => ['HY000', 1205, 'Lock wait timeout exceeded'],
            'expected' => LockContentionException::class,
        ];
        yield 'mysql server gone away (2006)' => [
            'driver' => 'mysql', 'errorInfo' => ['HY000', 2006, 'MySQL server has gone away'],
            'expected' => ConnectionLostException::class,
        ];
        yield 'mysql lost connection (2013)' => [
            'driver' => 'mysql', 'errorInfo' => ['HY000', 2013, 'Lost connection to MySQL server'],
            'expected' => ConnectionLostException::class,
        ];

        // PostgreSQL — SQLSTATEs are specific; the exact map suffices.
        yield 'pgsql unique (23505)' => [
            'driver' => 'pgsql', 'errorInfo' => ['23505', 7, 'duplicate key value violates unique constraint'],
            'expected' => UniqueConstraintViolationException::class,
        ];
        yield 'pgsql fk (23503)' => [
            'driver' => 'pgsql', 'errorInfo' => ['23503', 7, 'violates foreign key constraint'],
            'expected' => ForeignKeyConstraintViolationException::class,
        ];
        yield 'pgsql not null (23502)' => [
            'driver' => 'pgsql', 'errorInfo' => ['23502', 7, 'null value in column'],
            'expected' => NotNullConstraintViolationException::class,
        ];
        yield 'pgsql serialization failure (40001)' => [
            'driver' => 'pgsql', 'errorInfo' => ['40001', 7, 'could not serialize access'],
            'expected' => SerializationFailureException::class,
        ];
        yield 'pgsql deadlock (40P01)' => [
            'driver' => 'pgsql', 'errorInfo' => ['40P01', 7, 'deadlock detected'],
            'expected' => DeadlockException::class,
        ];
        yield 'pgsql lock not available (55P03)' => [
            'driver' => 'pgsql', 'errorInfo' => ['55P03', 7, 'could not obtain lock'],
            'expected' => LockContentionException::class,
        ];
        yield 'pgsql crash shutdown (57P02)' => [
            'driver' => 'pgsql', 'errorInfo' => ['57P02', 7, 'terminating connection due to crash'],
            'expected' => ConnectionLostException::class,
        ];
        yield 'pgsql cannot connect now (57P03)' => [
            'driver' => 'pgsql', 'errorInfo' => ['57P03', 7, 'the database system is starting up'],
            'expected' => ConnectionLostException::class,
        ];
        yield 'pgsql admin shutdown (57P01)' => [
            'driver' => 'pgsql', 'errorInfo' => ['57P01', 7, 'terminating connection'],
            'expected' => ConnectionLostException::class,
        ];
        yield 'pgsql check violation falls to constraint family (23514)' => [
            'driver' => 'pgsql', 'errorInfo' => ['23514', 7, 'violates check constraint'],
            'expected' => ConstraintViolationException::class,
        ];
        yield 'pgsql connection family (08006)' => [
            'driver' => 'pgsql', 'errorInfo' => ['08006', 7, 'connection failure'],
            'expected' => ConnectionLostException::class,
        ];

        // SQLite — default config is ambiguous; extended codes honored when supplied.
        yield 'sqlite default constraint code is ambiguous (23000+19)' => [
            'driver' => 'sqlite', 'errorInfo' => ['23000', 19, 'UNIQUE constraint failed: t.email'],
            'expected' => ConstraintViolationException::class,
        ];
        yield 'sqlite busy (5)' => [
            'driver' => 'sqlite', 'errorInfo' => ['HY000', 5, 'database is locked'],
            'expected' => LockContentionException::class,
        ];
        yield 'sqlite locked (6)' => [
            'driver' => 'sqlite', 'errorInfo' => ['HY000', 6, 'database table is locked'],
            'expected' => LockContentionException::class,
        ];
        yield 'sqlite extended unique (2067)' => [
            'driver' => 'sqlite', 'errorInfo' => ['HY000', 2067, 'UNIQUE constraint failed'],
            'expected' => UniqueConstraintViolationException::class,
        ];
        yield 'sqlite extended primary key (1555)' => [
            'driver' => 'sqlite', 'errorInfo' => ['HY000', 1555, 'UNIQUE constraint failed: t.id'],
            'expected' => UniqueConstraintViolationException::class,
        ];
        yield 'sqlite extended not null (1299)' => [
            'driver' => 'sqlite', 'errorInfo' => ['HY000', 1299, 'NOT NULL constraint failed'],
            'expected' => NotNullConstraintViolationException::class,
        ];
        yield 'sqlite extended fk (787)' => [
            'driver' => 'sqlite', 'errorInfo' => ['HY000', 787, 'FOREIGN KEY constraint failed'],
            'expected' => ForeignKeyConstraintViolationException::class,
        ];

        // Unknown → generic.
        yield 'unknown code and state → generic' => [
            'driver' => 'mysql', 'errorInfo' => ['HY000', 9999, 'something else'],
            'expected' => DatabaseException::class,
        ];
        yield 'unknown driver uses sqlstate maps' => [
            'driver' => 'sqlsrv', 'errorInfo' => ['23505', 0, 'dup'],
            'expected' => UniqueConstraintViolationException::class,
        ];
    }

    /**
     * @param array{0: string, 1?: int|string|null, 2?: string} $errorInfo
     * @param class-string<DatabaseException> $expected
     */
    #[Test]
    #[DataProvider('classificationCases')]
    public function classifiesDriverErrorShapes(string $driver, array $errorInfo, string $expected): void
    {
        $raw = $this->pdoException('SQLSTATE[' . $errorInfo[0] . ']: test', $errorInfo, $errorInfo[0]);

        $classified = $this->classifier->classify($raw, $driver);

        $this->assertSame($expected, get_class($classified));
        $this->assertSame($raw, $classified->getPrevious());
        $this->assertSame($driver, $classified->driver());
    }

    #[Test]
    public function alreadyClassifiedExceptionsPassThroughUnchanged(): void
    {
        $raw = $this->pdoException('m', ['40P01', 7, 'deadlock detected'], '40P01');
        $classified = DeadlockException::fromPdo($raw, 'pgsql');

        $this->assertSame($classified, $this->classifier->classify($classified, 'pgsql'));
    }

    #[Test]
    public function missingErrorInfoFallsBackToStringCode(): void
    {
        $raw = $this->pdoException('m', null, '23505');

        $this->assertInstanceOf(
            UniqueConstraintViolationException::class,
            $this->classifier->classify($raw, 'pgsql')
        );
    }

    #[Test]
    public function numericStringVendorCodesStillMatch(): void
    {
        $raw = $this->pdoException('m', ['HY000', '1205', 'Lock wait timeout'], 'HY000');

        $this->assertInstanceOf(
            LockContentionException::class,
            $this->classifier->classify($raw, 'mysql')
        );
    }

    #[Test]
    public function noErrorInformationAtAllIsGeneric(): void
    {
        $raw = $this->pdoException('driver exploded', null);

        $this->assertSame(DatabaseException::class, get_class($this->classifier->classify($raw, 'mysql')));
    }
}
