<?php

declare(strict_types=1);

namespace Glueful\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * Environment-driven Content-Security-Policy.
 *
 * `CSP_HEADER` is sent verbatim as `Content-Security-Policy` on every response that does not
 * already carry a policy — a mounted SPA's document policy, a controller's explicit header and
 * an extension's own middleware all keep precedence. `CSP_REPORT_ONLY=true` sends the value as
 * `Content-Security-Policy-Report-Only` instead so an operator can audit a policy in the browser
 * console before enforcing it. Nothing else is touched: no nonces (cached pages could not carry
 * them), no HSTS/CORP/COOP (those have their own switches and their own blast radius).
 */
final class ContentSecurityPolicy
{
    public const HEADER = 'Content-Security-Policy';
    public const REPORT_ONLY_HEADER = 'Content-Security-Policy-Report-Only';

    public static function fromEnv(): self
    {
        $policy = env('CSP_HEADER', '');
        $reportOnly = env('CSP_REPORT_ONLY', false);

        return new self(
            is_string($policy) ? trim($policy) : '',
            $reportOnly === true || $reportOnly === 'true' || $reportOnly === '1' || $reportOnly === 1,
        );
    }

    public function __construct(private readonly string $policy, private readonly bool $reportOnly = false)
    {
    }

    public function isConfigured(): bool
    {
        return $this->policy !== '';
    }

    /**
     * No-op when unconfigured or when the response already carries either CSP header.
     */
    public function applyToResponse(Response $response): void
    {
        if (!$this->isConfigured()) {
            return;
        }
        if ($response->headers->has(self::HEADER) || $response->headers->has(self::REPORT_ONLY_HEADER)) {
            return;
        }

        $response->headers->set($this->reportOnly ? self::REPORT_ONLY_HEADER : self::HEADER, $this->policy);
    }
}
