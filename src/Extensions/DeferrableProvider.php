<?php

declare(strict_types=1);

namespace Glueful\Extensions;

/**
 * Optional: providers can declare services they provide for future deferral.
 */
interface DeferrableProvider
{
    /** @return array<class-string> */
    public function provides(): array;
}