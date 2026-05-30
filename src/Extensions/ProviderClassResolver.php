<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Support\Version;

/**
 * The single resolution path for "which provider classes load, in what order".
 * Combines app providers (always) with resolved extensions (composer candidates
 * gated by extensions.enabled). Stateless; returns a ResolverResult whose
 * `providers` is the combined ordered list and whose `errors` are the extension
 * resolver's errors. Used by BOTH ExtensionManager and ContainerFactory.
 */
final class ProviderClassResolver
{
    public function __construct(
        private readonly AppProviderLoader $appProviders = new AppProviderLoader(),
        private readonly ExtensionResolver $resolver = new ExtensionResolver(),
    ) {
    }

    public function resolve(ApplicationContext $context): ResolverResult
    {
        $app = $this->appProviders->load($context);

        $candidates = (new PackageManifest($context))->getCandidates();
        $enabled = EnabledProviders::from($context);

        $extResult = $this->resolver->resolve($candidates, $enabled, Version::VERSION);

        // app providers first, then resolved extensions; dedupe preserving order
        $combined = array_values(array_unique([...$app, ...$extResult->providers]));

        return new ResolverResult($combined, $extResult->errors);
    }
}
