<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Loads app-level service providers — the application's OWN providers
 * (e.g. AppServiceProvider, EventServiceProvider). These are app-local classes,
 * not composer-discovered packages, so there is no discovery or validation:
 * the configured `enabled` list is loaded verbatim, in declared order.
 */
final class AppProviderLoader
{
    /** @return list<string> provider FQCNs in declared order */
    public function load(ApplicationContext $context): array
    {
        return EnabledProviders::from($context, 'serviceproviders.enabled');
    }
}
