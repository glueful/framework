<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database;

use Glueful\Database\Exceptions\ConstraintViolationException;
use Glueful\Database\Execution\ParameterBinder;
use Glueful\Database\Execution\QueryExecutor;
use Glueful\Database\QueryLogger;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Real SQLite failures through the real executor. Under default PDO
 * configuration (no extended result codes) every constraint kind is
 * indistinguishable, so the expected type is the generic
 * ConstraintViolationException — the spec's SQLite decision.
 */
final class TypedExceptionsIntegrationTest extends TestCase
{
    private QueryExecutor $executor;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE)');
        $pdo->exec(
            'CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id))'
        );

        $this->executor = new QueryExecutor($pdo, new ParameterBinder(), new QueryLogger());
    }

    #[Test]
    public function uniqueViolationIsClassifiedAndStillAPdoException(): void
    {
        $this->executor->executeStatement('INSERT INTO users (email) VALUES (?)', ['a@b.c']);

        try {
            $this->executor->executeStatement('INSERT INTO users (email) VALUES (?)', ['a@b.c']);
            $this->fail('Expected a constraint violation');
        } catch (ConstraintViolationException $e) {
            $this->assertInstanceOf(\PDOException::class, $e);
            $this->assertInstanceOf(\PDOException::class, $e->getPrevious());
            $this->assertSame('sqlite', $e->driver());
            $this->assertSame(19, $e->driverCode());
        }
    }

    #[Test]
    public function notNullViolationIsClassified(): void
    {
        $this->expectException(ConstraintViolationException::class);
        $this->executor->executeStatement('INSERT INTO users (email) VALUES (?)', [null]);
    }

    #[Test]
    public function foreignKeyViolationIsClassified(): void
    {
        $this->expectException(ConstraintViolationException::class);
        $this->executor->executeStatement('INSERT INTO posts (user_id) VALUES (?)', [999]);
    }
}
