<?php

declare(strict_types=1);

namespace Glueful\Api\RateLimiting;

use Glueful\Api\RateLimiting\Contracts\TierResolverInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Default tier resolver based on user authentication and subscription
 *
 * Resolves the user's rate limit tier from request attributes,
 * typically set by authentication middleware.
 */
final class TierResolver implements TierResolverInterface
{
    public function __construct(
        private readonly TierManager $tierManager
    ) {
    }

    public function resolve(Request $request): string
    {
        // Check for user in request attributes (set by auth middleware)
        $user = $request->attributes->get('user');

        if ($user === null) {
            return 'anonymous';
        }

        // Try to get tier from user data
        if (is_array($user)) {
            return $this->resolveTierFromArray($user);
        }

        if (is_object($user)) {
            return $this->resolveTierFromObject($user);
        }

        return $this->tierManager->getDefaultTier();
    }

    /**
     * Resolve tier from user array
     *
     * @param array<string, mixed> $user User data
     */
    private function resolveTierFromArray(array $user): string
    {
        // Check common tier/plan field names
        $planFields = ['tier', 'plan', 'subscription', 'subscription_tier', 'api_tier'];

        foreach ($planFields as $field) {
            if (isset($user[$field]) && is_string($user[$field])) {
                $tier = $this->normalizeTierName($user[$field]);
                if ($this->tierManager->hasTier($tier)) {
                    return $tier;
                }
            }
        }

        // Check for role-based tier mapping
        if (isset($user['roles']) && is_array($user['roles'])) {
            return $this->resolveFromRoles($user['roles']);
        }

        return 'free'; // Authenticated but no tier specified
    }

    /**
     * Resolve tier from user object
     */
    private function resolveTierFromObject(object $user): string
    {
        // Try common getter methods
        $methods = ['getTier', 'getPlan', 'getSubscription', 'getApiTier'];

        foreach ($methods as $method) {
            if (method_exists($user, $method)) {
                /** @phpstan-ignore-next-line Variable method call is intentional for dynamic dispatch */
                $value = $user->$method();
                if (is_string($value)) {
                    $tier = $this->normalizeTierName($value);
                    if ($this->tierManager->hasTier($tier)) {
                        return $tier;
                    }
                }
            }
        }

        // Check for property access
        $properties = ['tier', 'plan', 'subscription'];
        foreach ($properties as $prop) {
            if (property_exists($user, $prop)) {
                $value = $user->$prop;
                if (is_string($value)) {
                    $tier = $this->normalizeTierName($value);
                    if ($this->tierManager->hasTier($tier)) {
                        return $tier;
                    }
                }
            }
        }

        return 'free';
    }

    /**
     * Resolve tier from user roles
     *
     * @param array<string> $roles User roles
     */
    private function resolveFromRoles(array $roles): string
    {
        // Map roles to tiers (enterprise > pro > free)
        $roleMapping = [
            'admin' => 'enterprise',
            'enterprise' => 'enterprise',
            'pro' => 'pro',
            'professional' => 'pro',
            'business' => 'pro',
            'premium' => 'pro',
        ];

        foreach ($roles as $role) {
            $normalizedRole = strtolower((string) $role);
            if (isset($roleMapping[$normalizedRole])) {
                return $roleMapping[$normalizedRole];
            }
        }

        return 'free';
    }

    /**
     * Normalize tier name to standard format
     */
    private function normalizeTierName(string $tier): string
    {
        return match (strtolower($tier)) {
            'enterprise', 'unlimited', 'admin' => 'enterprise',
            'pro', 'professional', 'business', 'premium' => 'pro',
            'free', 'basic', 'starter' => 'free',
            'anonymous', 'guest', 'unauthenticated' => 'anonymous',
            default => strtolower($tier)
        };
    }
}
