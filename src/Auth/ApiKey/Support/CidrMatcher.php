<?php

declare(strict_types=1);

namespace Glueful\Auth\ApiKey\Support;

/**
 * IPv4 CIDR / single-IP matcher.
 *
 * Inline implementation (no external dependency) — fail-closed on malformed
 * input. IPv6 support can be added later when the framework's overall IPv6
 * story is settled.
 */
final class CidrMatcher
{
    public static function matches(string $ip, string $cidrOrIp): bool
    {
        $client = @inet_pton($ip);
        if ($client === false) {
            return false;
        }

        if (!str_contains($cidrOrIp, '/')) {
            $target = @inet_pton($cidrOrIp);
            return $target !== false && hash_equals($target, $client);
        }

        [$subnet, $bits] = explode('/', $cidrOrIp, 2);
        $subnetBin = @inet_pton($subnet);
        if ($subnetBin === false || !ctype_digit($bits)) {
            return false;
        }

        $prefixLen = (int) $bits;
        if ($prefixLen < 0 || $prefixLen > 32 || strlen($subnetBin) !== 4) {
            return false; // IPv4 only for now
        }

        $mask = $prefixLen === 0 ? 0 : ((~0) << (32 - $prefixLen)) & 0xFFFFFFFF;
        $clientInt = unpack('N', $client)[1];
        $subnetInt = unpack('N', $subnetBin)[1];

        return ($clientInt & $mask) === ($subnetInt & $mask);
    }

    /**
     * Empty allowlist means "no restriction" → matches everything.
     *
     * @param array<int, string> $allowlist
     */
    public static function matchesAny(string $ip, array $allowlist): bool
    {
        if ($allowlist === []) {
            return true;
        }

        foreach ($allowlist as $entry) {
            if (self::matches($ip, $entry)) {
                return true;
            }
        }

        return false;
    }
}
