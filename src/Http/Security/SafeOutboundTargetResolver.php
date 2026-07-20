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
            if (filter_var($ip, FILTER_VALIDATE_IP, self::BLOCKED_IP_FLAGS) === false) {
                $prefix = $context === 'webhook' ? 'Unsafe webhook URL' : 'Unsafe URL';
                throw new HttpClientException($prefix . ': host resolves to a private or reserved address');
            }
        }
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
