<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Stubs;

use Glueful\Database\ORM\Model;

/**
 * Minimal model stub for N+1 detector tests. Defined once in Support/
 * to avoid duplicate-class errors across test files that need this fixture.
 */
class HydrationTaggingTestModel extends Model
{
    protected string $table = 'fake';
    public bool $exists = false;
}
