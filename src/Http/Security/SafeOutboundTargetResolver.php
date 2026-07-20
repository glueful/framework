<?php

declare(strict_types=1);

namespace Glueful\Http\Security;

use Glueful\Http\Exceptions\HttpClientException;

/**
 * SSRF-safe outbound target resolution — the single policy for validating and
 * DNS-pinning outbound HTTP(S) targets.
 *
 * Exposes two explicit profiles over one shared parse/canonicalize/resolve/reject
 * core so the blocked-range policy can never drift between callers:
 *
 * - resolveSafeFetch(): the historical `Client::assertSafeFetchUrl()` behavior
 *   (http/https, optional allow-private-hosts override for operator-configured
 *   endpoints), preserved byte-for-byte for `Client::safeRequest()`/`safeRequestAsync()`.
 * - resolveWebhook(): a strict profile for third-party webhook delivery — HTTPS
 *   only, no credentials/fragment/non-default-port/IP-literal hosts, IDNA
 *   canonicalization with malformed/ambiguous-encoding rejection, and rejection if
 *   ANY resolved A/AAAA address falls in blocked space. Owns its own host
 *   canonicalization; it does not depend on any tenancy `HostNormalizer`.
 *
 * Both profiles resolve every A and AAAA record for the host and reject the target
 * if ANY resolved address is private/loopback/link-local/metadata/reserved (IPv4 or
 * IPv6) — this all-addresses check is what makes the pinned `resolve[host] = ip`
 * connection safe against a host that resolves to more than one address.
 */
final class SafeOutboundTargetResolver
{
    private const BLOCKED_IP_FLAGS = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

    /**
     * Supplemental IPv4 blocked ranges checked ONLY by resolveWebhook() (belt-and-
     * suspenders on top of BLOCKED_IP_FLAGS). FILTER_FLAG_NO_PRIV_RANGE and
     * FILTER_FLAG_NO_RES_RANGE do not cover RFC6598 CGNAT space or several IANA
     * special-purpose ranges — left open here, a webhook host resolving into one of
     * them (e.g. 100.64.0.0/10 on CGNAT-routed pod networking) would pass resolution
     * and connect. resolveSafeFetch() intentionally does NOT use this list — its
     * blocked-range policy stays exactly BLOCKED_IP_FLAGS, byte-identical to the
     * pre-extraction Client::assertSafeFetchUrl() behavior.
     */
    private const BLOCKED_WEBHOOK_IPV4_CIDRS = [
        '0.0.0.0/8',        // "this" network
        '10.0.0.0/8',       // RFC1918 private
        '100.64.0.0/10',    // RFC6598 CGNAT (shared address space)
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local, incl. cloud metadata (169.254.169.254)
        '172.16.0.0/12',    // RFC1918 private
        '192.0.0.0/24',     // IANA IETF protocol assignments
        '192.0.2.0/24',     // TEST-NET-1 documentation
        '192.168.0.0/16',   // RFC1918 private
        '198.18.0.0/15',    // RFC2544 benchmarking
        '198.51.100.0/24',  // TEST-NET-2 documentation
        '203.0.113.0/24',   // TEST-NET-3 documentation
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved for future use
        '255.255.255.255/32', // limited broadcast
    ];

    /** Supplemental IPv6 blocked ranges checked ONLY by resolveWebhook() (see above). */
    private const BLOCKED_WEBHOOK_IPV6_CIDRS = [
        '::1/128',        // loopback
        '::/128',          // unspecified
        '100::/64',        // discard-only
        '2001:db8::/32',   // documentation
        'fc00::/7',        // unique local (ULA)
        'fe80::/10',       // link-local
    ];

    /**
     * IPv6 transition ranges that embed an IPv4 address: the resolved address itself
     * may fall outside BLOCKED_WEBHOOK_IPV6_CIDRS while the address it decodes to is
     * blocked (e.g. `64:ff9b::7f00:1` is NAT64-wrapped 127.0.0.1). Value is the byte
     * offset of the embedded 4-byte IPv4 address within the 16-byte IPv6 binary form.
     *
     * @var array<string, int>
     */
    private const EMBEDDED_IPV4_IPV6_RANGES = [
        '::ffff:0:0/96' => 12, // IPv4-mapped
        '64:ff9b::/96' => 12,  // NAT64
        '2002::/16' => 2,      // 6to4
    ];

    /**
     * IDNA "dot" look-alike codepoints (ideographic full stop, fullwidth full stop,
     * halfwidth ideographic full stop) that UTS46 silently maps to U+002E during
     * canonicalization. A host containing one of these was not written with a dot
     * by whoever supplied it but resolves as though it were — rejected outright
     * rather than trusted to canonicalize predictably.
     */
    private const IDNA_DOT_LOOKALIKES = ["\u{3002}", "\u{FF0E}", "\u{FF61}"];

    private const IDNA_FLAGS = IDNA_DEFAULT
        | IDNA_USE_STD3_RULES
        | IDNA_CHECK_BIDI
        | IDNA_CHECK_CONTEXTJ
        | IDNA_NONTRANSITIONAL_TO_ASCII;

    /**
     * @param (\Closure(string): list<string>)|null $dnsLookup Test seam only: overrides system DNS
     *        resolution (gethostbynamel()/dns_get_record()) with a caller-supplied A/AAAA lookup
     *        for a given host. Production callers should leave this null.
     */
    public function __construct(private readonly ?\Closure $dnsLookup = null)
    {
    }

    /**
     * Preserves `Client::assertSafeFetchUrl()`'s historical behavior byte-for-byte:
     * http/https only; `localhost` and private/reserved ranges rejected unless
     * $allowPrivateHosts is set (for operator-configured internal endpoints, e.g.
     * internal health checks); every resolved A/AAAA address is checked; the first
     * resolved address is pinned.
     *
     * @throws HttpClientException when the URL, scheme, host, or resolved address(es) are unsafe
     */
    public function resolveSafeFetch(string $url, bool $allowPrivateHosts = false): ResolvedOutboundTarget
    {
        $parts = parse_url($url);
        if ($parts === false) {
            throw new HttpClientException('Unsafe URL: invalid URL');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new HttpClientException('Unsafe URL: only http and https schemes are allowed');
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), "[] \t\n\r\0\x0B"));
        if ($host === '' || (!$allowPrivateHosts && $host === 'localhost')) {
            throw new HttpClientException('Unsafe URL: host is not allowed');
        }

        $ips = $this->resolveHostIps($host);
        if ($ips === []) {
            throw new HttpClientException('Unsafe URL: host could not be resolved');
        }

        if (!$allowPrivateHosts) {
            $this->rejectIfAnyBlocked($ips);
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return new ResolvedOutboundTarget(
            canonicalUrl: $url,
            host: $host,
            port: $port,
            ip: $ips[0]
        );
    }

    /**
     * Strict profile for third-party webhook delivery. Requires HTTPS, rejects
     * credentials, fragments, non-default ports, and IP-literal hosts, canonicalizes
     * the host via IDNA/UTS46 (rejecting malformed labels and ambiguous encodings),
     * and rejects the target if ANY resolved A/AAAA address is blocked space.
     *
     * @throws HttpClientException when the URL, scheme, host, or resolved address(es) are unsafe
     */
    public function resolveWebhook(string $url): ResolvedOutboundTarget
    {
        $parts = $this->parseUrlSafely($url);
        if ($parts === false) {
            throw new HttpClientException('Unsafe webhook URL: invalid URL');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            throw new HttpClientException('Unsafe webhook URL: only https is allowed');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new HttpClientException('Unsafe webhook URL: credentials are not allowed');
        }

        if (isset($parts['fragment'])) {
            throw new HttpClientException('Unsafe webhook URL: fragment is not allowed');
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && $port !== 443) {
            throw new HttpClientException('Unsafe webhook URL: only the default HTTPS port is allowed');
        }

        $rawHost = (string) ($parts['host'] ?? '');
        if ($rawHost === '') {
            throw new HttpClientException('Unsafe webhook URL: host is not allowed');
        }

        $bracketed = str_starts_with($rawHost, '[') && str_ends_with($rawHost, ']');
        $hostForIpCheck = $bracketed ? substr($rawHost, 1, -1) : $rawHost;
        if ($bracketed || filter_var($hostForIpCheck, FILTER_VALIDATE_IP) !== false) {
            throw new HttpClientException('Unsafe webhook URL: IP-literal hosts are not allowed');
        }

        $asciiHost = $this->canonicalizeIdnaHost(strtolower($rawHost));

        $ips = $this->resolveHostIps($asciiHost);
        if ($ips === []) {
            throw new HttpClientException('Unsafe webhook URL: host could not be resolved');
        }

        $this->rejectIfAnyBlocked($ips, 'webhook');

        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return new ResolvedOutboundTarget(
            canonicalUrl: 'https://' . $asciiHost . $path . $query,
            host: $asciiHost,
            port: 443,
            ip: $ips[0]
        );
    }

    /**
     * PHP's parse_url() has a long-standing parser quirk where certain multi-byte
     * UTF-8 sequences in the host component get silently corrupted (specific
     * continuation bytes replaced) rather than passed through — which would let a
     * hand-crafted IDN host slip past canonicalization as mangled bytes instead of
     * being properly rejected or converted. Percent-encoding every non-ASCII byte
     * before parsing — parse_url is byte-safe for a purely-ASCII string — and then
     * decoding just the extracted host afterward sidesteps the corruption so an IDN
     * host is inspected exactly as the caller supplied it.
     *
     * @return array<string, int|string>|false
     */
    private function parseUrlSafely(string $url): array|false
    {
        $asciiUrl = preg_replace_callback(
            '/[\x80-\xFF]/',
            static fn(array $m): string => rawurlencode($m[0]),
            $url
        );

        $parts = parse_url($asciiUrl ?? $url);
        if ($parts === false) {
            return false;
        }

        if (isset($parts['host']) && is_string($parts['host'])) {
            $parts['host'] = rawurldecode($parts['host']);
        }

        return $parts;
    }

    /**
     * IDNA/UTS46 canonicalization with rejection of malformed labels, deviant-
     * processing ambiguity, and dot-lookalike codepoints that UTS46 would silently
     * fold into label separators.
     *
     * @throws HttpClientException when the host is malformed or its encoding is ambiguous
     */
    private function canonicalizeIdnaHost(string $host): string
    {
        foreach (self::IDNA_DOT_LOOKALIKES as $lookalike) {
            if (str_contains($host, $lookalike)) {
                throw new HttpClientException('Unsafe webhook URL: ambiguous host encoding');
            }
        }

        $info = [];
        $ascii = idn_to_ascii($host, self::IDNA_FLAGS, INTL_IDNA_VARIANT_UTS46, $info);
        if (!is_string($ascii) || ($info['errors'] ?? 0) !== 0) {
            throw new HttpClientException('Unsafe webhook URL: malformed international domain name');
        }

        // Idempotency guard: a canonical ASCII host must be stable under re-canonicalization,
        // otherwise the original input was an ambiguous encoding of more than one host.
        $reinfo = [];
        $reascii = idn_to_ascii($ascii, self::IDNA_FLAGS, INTL_IDNA_VARIANT_UTS46, $reinfo);
        if ($reascii !== $ascii || ($reinfo['errors'] ?? 0) !== 0) {
            throw new HttpClientException('Unsafe webhook URL: ambiguous host encoding');
        }

        return $ascii;
    }

    /**
     * @param list<string> $ips
     * @throws HttpClientException when any address is private/loopback/link-local/metadata/reserved
     */
    private function rejectIfAnyBlocked(array $ips, string $context = 'safe'): void
    {
        foreach ($ips as $ip) {
            $blocked = filter_var($ip, FILTER_VALIDATE_IP, self::BLOCKED_IP_FLAGS) === false;

            // Supplemental check: webhook only, never applied to resolveSafeFetch().
            if (!$blocked && $context === 'webhook') {
                $blocked = $this->isSupplementalBlockedWebhookAddress($ip);
            }

            if ($blocked) {
                $prefix = $context === 'webhook' ? 'Unsafe webhook URL' : 'Unsafe URL';
                throw new HttpClientException($prefix . ': host resolves to a private or reserved address');
            }
        }
    }

    /**
     * Belt-and-suspenders check applied only by the webhook profile: covers CGNAT and
     * IANA special-purpose ranges that BLOCKED_IP_FLAGS leaves open, plus IPv6
     * transition ranges whose embedded IPv4 address must be decoded and re-checked.
     */
    private function isSupplementalBlockedWebhookAddress(string $ip): bool
    {
        if (str_contains($ip, ':')) {
            return $this->isBlockedWebhookIpv6($ip);
        }

        return $this->isBlockedWebhookIpv4($ip);
    }

    private function isBlockedWebhookIpv4(string $ip): bool
    {
        foreach (self::BLOCKED_WEBHOOK_IPV4_CIDRS as $cidr) {
            if ($this->ipv4InCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function isBlockedWebhookIpv6(string $ip): bool
    {
        foreach (self::BLOCKED_WEBHOOK_IPV6_CIDRS as $cidr) {
            if ($this->ipv6InCidr($ip, $cidr)) {
                return true;
            }
        }

        foreach (self::EMBEDDED_IPV4_IPV6_RANGES as $cidr => $byteOffset) {
            if (!$this->ipv6InCidr($ip, $cidr)) {
                continue;
            }

            $embedded = $this->extractEmbeddedIpv4($ip, $byteOffset);

            return $embedded === null || $this->isBlockedWebhookIpv4($embedded);
        }

        return false;
    }

    /**
     * Decodes the 4-byte IPv4 address embedded at $byteOffset within an IPv6
     * transition-range address (IPv4-mapped, NAT64, or 6to4).
     */
    private function extractEmbeddedIpv4(string $ip, int $byteOffset): ?string
    {
        $binary = @inet_pton($ip);
        if ($binary === false || strlen($binary) !== 16) {
            return null;
        }

        $embedded = @inet_ntop(substr($binary, $byteOffset, 4));

        return $embedded === false ? null : $embedded;
    }

    /**
     * IPv4 CIDR-membership check via ip2long() + bitmask comparison.
     */
    private function ipv4InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $prefixPart] = explode('/', $cidr, 2);
        $prefix = (int) $prefixPart;

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $prefix === 0 ? 0 : ((~0 << (32 - $prefix)) & 0xFFFFFFFF);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * IPv6 CIDR-membership check via inet_pton() + byte-prefix comparison.
     */
    private function ipv6InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $prefixPart] = explode('/', $cidr, 2);
        $prefix = (int) $prefixPart;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== 16 || strlen($subnetBin) !== 16) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }

    /**
     * Resolve every A and AAAA record for a host (or return the literal address for
     * an IP-literal host). Shared by both profiles so the resolution step — and the
     * blocked-range check applied to its result — never drifts between them.
     *
     * @return list<string>
     */
    private function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if ($this->dnsLookup !== null) {
            return array_values(array_unique(($this->dnsLookup)($host)));
        }

        $ips = [];
        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $ips = array_merge($ips, $ipv4);
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
