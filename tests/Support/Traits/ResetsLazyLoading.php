<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Traits;

use Glueful\Database\ORM\Model;

/**
 * PHPUnit trait that clears N+1 detector static state in tearDown().
 * Include this in any test class that mutates lazy-loading global state.
 */
trait ResetsLazyLoading
{
    protected function tearDown(): void
    {
        Model::resetLazyLoadingState();
        parent::tearDown();
    }
}
