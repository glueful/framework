<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Auth\Contracts\IdentityClaimsProviderInterface;

/**
 * Applies the account-status gate and composes claims from all registered providers.
 * Two invariants (spec §4 trust rule):
 *  - identity facts (uuid/email/username/status) are never changed by a provider;
 *  - claims are ADDED, never wiped — list claims (roles/scopes/permissions) are UNIONed, so a
 *    provider returning empty/default lists cannot erase an earlier provider's claims.
 */
final class IdentityResolver
{
    /**
     * @param list<IdentityClaimsProviderInterface> $claimsProviders
     * @param list<string> $allowedStatuses Account statuses permitted to log in (default ['active']).
     */
    public function __construct(
        private array $claimsProviders,
        private array $allowedStatuses = ['active'],
    ) {
    }

    /**
     * @return UserIdentity|null Enriched identity, or null if the status gate rejects it.
     */
    public function resolve(UserIdentity $identity): ?UserIdentity
    {
        if (!$this->statusAllowsLogin($identity->status())) {
            return null;
        }

        foreach ($this->claimsProviders as $provider) {
            $contributed = $provider->enrich($identity)->claims();
            // withClaims() preserves identity facts (re-pin); mergeClaims() makes it additive.
            $identity = $identity->withClaims($this->mergeClaims($identity->claims(), $contributed));
        }

        return $identity;
    }

    /**
     * null = "store has no opinion" = allowed; otherwise the status must be in the allow-list.
     * Allow-list (never deny-list) keeps it fail-closed.
     */
    private function statusAllowsLogin(?string $status): bool
    {
        return $status === null || in_array($status, $this->allowedStatuses, true);
    }

    /**
     * Non-destructive merge. List claims (roles/scopes + list-style permissions) are UNIONed so
     * empty contributions never wipe earlier claims; map-style permissions merge per-resource;
     * any other claim takes the incoming value when present.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $incoming
     * @return array<string,mixed>
     */
    private function mergeClaims(array $base, array $incoming): array
    {
        $merged = array_merge($base, $incoming);

        foreach (['roles', 'scopes'] as $key) {
            $merged[$key] = $this->unionList($base[$key] ?? [], $incoming[$key] ?? []);
        }

        $bp = is_array($base['permissions'] ?? null) ? $base['permissions'] : [];
        $ip = is_array($incoming['permissions'] ?? null) ? $incoming['permissions'] : [];
        if ($bp !== [] || $ip !== []) {
            if (array_is_list($bp) && array_is_list($ip)) {
                $merged['permissions'] = $this->unionList($bp, $ip);
            } else {
                // map shape e.g. ['system' => ['a','b']] — union each resource's list.
                $out = $bp;
                foreach ($ip as $resource => $perms) {
                    $out[$resource] = $this->unionList($out[$resource] ?? [], is_array($perms) ? $perms : [$perms]);
                }
                $merged['permissions'] = $out;
            }
        }

        return $merged;
    }

    /**
     * @return list<mixed>
     */
    private function unionList(mixed $a, mixed $b): array
    {
        $a = is_array($a) ? array_values($a) : [];
        $b = is_array($b) ? array_values($b) : [];
        return array_values(array_unique([...$a, ...$b]));
    }
}
