<?php

declare(strict_types=1);

namespace Glueful\Api\RateLimiting;

use Glueful\Api\RateLimiting\Contracts\RateLimiterInterface;
use Glueful\Api\RateLimiting\Contracts\StorageInterface;
use Glueful\Api\RateLimiting\Contracts\TierResolverInterface;
use Glueful\Api\RateLimiting\Limiters\FixedWindowLimiter;
use Glueful\Api\RateLimiting\Limiters\SlidingWindowLimiter;
use Glueful\Api\RateLimiting\Limiters\TokenBucketLimiter;
use Symfony\Component\HttpFoundation\Request;

/**
 * Central orchestrator for rate limiting
 *
 * Coordinates limiters, tier resolution, and attribute configuration
 * to provide a unified rate limiting interface.
 */
final class RateLimitManager
{
    /**
     * @var array<string, RateLimiterInterface> Cached limiter instances
     */
    private array $limiters = [];

    /**
     * @param StorageInterface $storage Storage backend
     * @param TierResolverInterface $tierResolver Tier resolver
     * @param TierManager $tierManager Tier configuration
     * @param array<string, mixed> $config Additional configuration
     */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly TierResolverInterface $tierResolver,
        private readonly TierManager $tierManager,
        private readonly array $config = []
    ) {
    }

    /**
     * Attempt request against all applicable rate limits
     *
     * @param Request $request The HTTP request
     * @param array<array<string,mixed>> $limits Rate limit configurations from route
     * @param int $cost Cost multiplier for this request
     * @return RateLimitResult Most restrictive result
     */
    public function attempt(Request $request, array $limits, int $cost = 1): RateLimitResult
    {
        $tier = $this->tierResolver->resolve($request);

        // Check if tier is completely unlimited
        if ($this->tierManager->isCompletelyUnlimited($tier)) {
            return RateLimitResult::unlimited($tier);
        }

        // Use default limits if none provided
        if (count($limits) === 0) {
            $limits = $this->getDefaultLimits($tier);
        }

        // Fall back to global defaults if still empty
        if (count($limits) === 0) {
            $limits = $this->getGlobalDefaults();
        }

        $mostRestrictive = null;

        foreach ($limits as $limitConfig) {
            // Skip if limit is for a different tier
            $limitTier = $limitConfig['tier'] ?? null;
            if ($limitTier !== null && $limitTier !== $tier) {
                continue;
            }

            // Skip unlimited limits
            $attempts = $limitConfig['attempts'] ?? 0;
            if ($attempts === 0) {
                continue;
            }

            // Get appropriate limiter
            $algorithm = $limitConfig['algorithm'] ?? $this->config['algorithm'] ?? 'sliding';
            $limiter = $this->getLimiter($algorithm);

            // Build rate limit key
            $key = $this->buildKey($request, $limitConfig, $tier);

            // Apply cost
            $effectiveCost = ($limitConfig['cost'] ?? 1) * $cost;

            // Attempt
            $result = $limiter->attempt(
                $key,
                $attempts,
                $limitConfig['decaySeconds'] ?? 60,
                $effectiveCost
            );

            // Track most restrictive result
            $isMoreRestrictive = $mostRestrictive === null
                || (!$result->allowed && $mostRestrictive->allowed)
                || ($result->allowed === $mostRestrictive->allowed
                    && $result->remaining < $mostRestrictive->remaining);

            if ($isMoreRestrictive) {
                $mostRestrictive = new RateLimitResult(
                    allowed: $result->allowed,
                    limit: $result->limit,
                    remaining: $result->remaining,
                    resetAt: $result->resetAt,
                    retryAfter: $result->retryAfter,
                    cost: $effectiveCost,
                    tier: $tier,
                    policy: $this->formatPolicy($result)
                );
            }

            // Short-circuit if limit exceeded
            if (!$result->allowed) {
                break;
            }
        }

        return $mostRestrictive ?? RateLimitResult::unlimited($tier);
    }

    /**
     * Check limits without consuming
     *
     * @param Request $request The HTTP request
     * @param array<array<string,mixed>> $limits Rate limit configurations
     * @return RateLimitResult Current status
     */
    public function check(Request $request, array $limits): RateLimitResult
    {
        $tier = $this->tierResolver->resolve($request);

        if ($this->tierManager->isCompletelyUnlimited($tier)) {
            return RateLimitResult::unlimited($tier);
        }

        if (count($limits) === 0) {
            $limits = $this->getDefaultLimits($tier);
        }

        $mostRestrictive = null;

        foreach ($limits as $limitConfig) {
            $limitTier = $limitConfig['tier'] ?? null;
            if ($limitTier !== null && $limitTier !== $tier) {
                continue;
            }

            $attempts = $limitConfig['attempts'] ?? 0;
            if ($attempts === 0) {
                continue;
            }

            $algorithm = $limitConfig['algorithm'] ?? 'sliding';
            $limiter = $this->getLimiter($algorithm);
            $key = $this->buildKey($request, $limitConfig, $tier);

            $result = $limiter->check(
                $key,
                $attempts,
                $limitConfig['decaySeconds'] ?? 60
            );

            $isMoreRestrictive = $mostRestrictive === null
                || $result->remaining < $mostRestrictive->remaining;

            if ($isMoreRestrictive) {
                $mostRestrictive = $result->withTier($tier);
            }
        }

        return $mostRestrictive ?? RateLimitResult::unlimited($tier);
    }

    /**
     * Get the current tier for a request
     */
    public function getTier(Request $request): string
    {
        return $this->tierResolver->resolve($request);
    }

    /**
     * Get limiter by algorithm name
     */
    private function getLimiter(string $algorithm): RateLimiterInterface
    {
        if (!isset($this->limiters[$algorithm])) {
            $this->limiters[$algorithm] = match ($algorithm) {
                'fixed' => new FixedWindowLimiter($this->storage),
                'sliding' => new SlidingWindowLimiter($this->storage),
                'bucket', 'token_bucket' => new TokenBucketLimiter($this->storage),
                default => new SlidingWindowLimiter($this->storage)
            };
        }

        return $this->limiters[$algorithm];
    }

    /**
     * Build rate limit key from request and configuration
     *
     * @param Request $request The HTTP request
     * @param array<string, mixed> $limitConfig Limit configuration
     * @param string $tier User tier
     */
    private function buildKey(Request $request, array $limitConfig, string $tier): string
    {
        // Custom key pattern
        $keyPattern = $limitConfig['key'] ?? null;
        if (is_string($keyPattern) && $keyPattern !== '') {
            return $this->resolveKeyPattern($keyPattern, $request, $tier);
        }

        $by = $limitConfig['by'] ?? 'ip';
        $window = $limitConfig['decaySeconds'] ?? 60;

        $baseKey = match ($by) {
            'user' => $this->getUserKey($request) ?? $this->getIpKey($request),
            'endpoint' => $this->getEndpointKey($request),
            'ip' => $this->getIpKey($request),
            default => $this->getIpKey($request)
        };

        return "{$baseKey}:{$tier}:{$window}";
    }

    /**
     * Get IP-based key
     */
    private function getIpKey(Request $request): string
    {
        return 'ip:' . ($request->getClientIp() ?? '0.0.0.0');
    }

    /**
     * Get user-based key
     */
    private function getUserKey(Request $request): ?string
    {
        $user = $request->attributes->get('user');

        if ($user === null) {
            return null;
        }

        if (is_array($user)) {
            $userId = $user['id'] ?? $user['uuid'] ?? null;
        } elseif (is_object($user) && method_exists($user, 'getId')) {
            $userId = $user->getId();
        } else {
            return null;
        }

        return $userId !== null ? "user:{$userId}" : null;
    }

    /**
     * Get endpoint-based key
     */
    private function getEndpointKey(Request $request): string
    {
        $path = $request->getPathInfo();
        $method = $request->getMethod();
        $identifier = $this->getUserKey($request) ?? $this->getIpKey($request);

        return "endpoint:{$method}:{$path}:{$identifier}";
    }

    /**
     * Resolve custom key pattern
     */
    private function resolveKeyPattern(string $pattern, Request $request, string $tier): string
    {
        $replacements = [
            '{ip}' => $request->getClientIp() ?? '0.0.0.0',
            '{user}' => $this->getUserKey($request) ?? 'anonymous',
            '{path}' => $request->getPathInfo(),
            '{method}' => $request->getMethod(),
            '{tier}' => $tier,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $pattern);
    }

    /**
     * Get default limits based on tier configuration
     *
     * @return array<array<string,mixed>>
     */
    private function getDefaultLimits(string $tier): array
    {
        return $this->tierManager->createDefaultLimits($tier);
    }

    /**
     * Get global default limits from configuration
     *
     * @return array<array<string,mixed>>
     */
    private function getGlobalDefaults(): array
    {
        $defaults = $this->config['defaults'] ?? [];
        $ipDefaults = $defaults['ip'] ?? ['max_attempts' => 60, 'window_seconds' => 60];

        return [[
            'attempts' => $ipDefaults['max_attempts'] ?? 60,
            'decaySeconds' => $ipDefaults['window_seconds'] ?? 60,
            'algorithm' => $this->config['algorithm'] ?? 'sliding',
            'by' => 'ip',
        ]];
    }

    /**
     * Format policy string for header
     */
    private function formatPolicy(RateLimitResult $result): string
    {
        $window = $result->getWindowSeconds();

        return "{$result->limit};w={$window}";
    }
}
