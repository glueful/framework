<?php

declare(strict_types=1);

namespace Glueful\Extensions;

/**
 * Marker for providers that implement booAfter(): Class[].
 * Deps must be installed before OrderedProvider.boot() is called.
 * The OrderedProvider implementation can use DI at boot() time to access deps.
 */
interface OrderedProvider
{
    /**
     * Service providers in this installation that MUST boot before this provider.
     * Names as class-strings, e.g., ['App\Providers\PaymentProvider'].
     *
     * @return array<class-string<ServiceProvider>>
     */
    public function bootAfter(): array;

    /**
     * Numeric priority for order within the same booAfter() dependency level.
     * Lower number = boots first.
     */
    public function priority(): int;
}
