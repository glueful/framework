<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Contracts;

use Glueful\Database\ORM\Builder;

/**
 * Contract for scopes that add builder macros or behaviors.
 */
interface ExtendsBuilder
{
    /**
     * Extend the ORM builder instance.
     */
    public function extend(Builder $builder): void;
}
