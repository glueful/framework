<?php

declare(strict_types=1);

namespace Glueful\Extensions;

final class ProviderOrderCycleException extends \RuntimeException
{
    /** @param list<class-string> $blockedProviders */
    public function __construct(public readonly array $blockedProviders)
    {
        parent::__construct(
            'Providers blocked by a load-order cycle: ' . implode(', ', $blockedProviders)
            . '. Inspect the affected loadAfter() declarations.'
        );
    }
}
