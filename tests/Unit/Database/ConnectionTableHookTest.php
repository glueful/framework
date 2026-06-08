<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database;

use Glueful\Database\Connection;
use PHPUnit\Framework\TestCase;

final class ConnectionTableHookTest extends TestCase
{
    protected function tearDown(): void
    {
        Connection::clearTableHooks();
    }

    public function test_all_table_hooks_run_in_registration_order(): void
    {
        $calls = [];
        Connection::addTableHook(function ($qb, string $table, $conn) use (&$calls) {
            $calls[] = "a:$table";
        });
        Connection::addTableHook(function ($qb, string $table, $conn) use (&$calls) {
            $calls[] = "b:$table";
        });

        $conn = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);
        $conn->table('invoices');

        // Both hooks ran, in registration order — no last-writer-wins.
        $this->assertSame(['a:invoices', 'b:invoices'], $calls);
    }

    public function test_no_hooks_is_a_noop(): void
    {
        $conn = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);

        // Should not error when no hooks are registered.
        $qb = $conn->table('invoices');
        $this->assertNotNull($qb);
    }
}
