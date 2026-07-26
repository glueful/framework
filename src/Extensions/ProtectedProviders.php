<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Generic-activation refusal for providers whose enable/disable is OWNED elsewhere — a domain
 * lifecycle flow (e.g. glueful/tenancy's enablement state machine) or a product's
 * bundled-required set. Enforcement lives here, ABOVE the policy-free ExtensionStateWriter,
 * so owning flows keep using the low-level writer directly. Host config shape:
 *
 *   'protected' => [
 *       'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider' => [
 *           'reason' => 'Managed by the tenancy enablement flow — use the workspaces admin.',
 *           'managed_by' => 'glueful/tenancy enablement',
 *       ],
 *   ],
 */
final class ProtectedProviders
{
    public static function refusalFor(ApplicationContext $context, string $provider): ?string
    {
        $map = config($context, 'extensions.protected', []);
        if (!is_array($map) || !array_key_exists($provider, $map)) {
            return null;
        }
        $entry = is_array($map[$provider]) ? $map[$provider] : [];
        $reason = is_string($entry['reason'] ?? null) && $entry['reason'] !== ''
            ? $entry['reason']
            : 'This provider\'s activation is managed outside the generic extension commands.';
        $owner = is_string($entry['managed_by'] ?? null) && $entry['managed_by'] !== ''
            ? ' (managed by: ' . $entry['managed_by'] . ')'
            : '';

        return $reason . $owner;
    }
}
