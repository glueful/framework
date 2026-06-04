<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Catalog;

use Glueful\Permissions\Catalog\SyncResult;
use PHPUnit\Framework\TestCase;

final class SyncResultTest extends TestCase
{
    public function test_holds_counts_and_stale(): void
    {
        $r = new SyncResult(created: 2, updated: 1, unchanged: 5, stale: ['blog.old']);
        self::assertSame(2, $r->created);
        self::assertSame(1, $r->updated);
        self::assertSame(5, $r->unchanged);
        self::assertSame(['blog.old'], $r->stale);
    }
}
