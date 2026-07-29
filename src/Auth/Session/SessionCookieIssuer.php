<?php

declare(strict_types=1);

namespace Glueful\Auth\Session;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only place session cookie attributes are set.
 *
 * `issue()` accepts an {@see AuthenticatedSession} and nothing else: a login that is still
 * awaiting second-factor verification cannot produce that type, so issuing cookies for an
 * unfinished login is not an error to remember to avoid — it is unrepresentable.
 *
 * Rotation is `issue()` with the rotated session; a browser replaces a cookie by receiving a
 * new value for the same name and path.
 */
final class SessionCookieIssuer
{
    public function __construct(private readonly SessionCookieConfig $config)
    {
    }

    public function issue(Response $response, AuthenticatedSession $session): Response
    {
        $response->headers->setCookie($this->cookie(
            $this->config->accessName,
            $session->accessToken,
            time() + max(0, $session->expiresIn),
            $this->config->path,
        ));

        $response->headers->setCookie($this->cookie(
            $this->config->refreshName,
            $session->refreshToken,
            time() + $this->config->refreshTtl,
            $this->config->refreshPath,
        ));

        return $response;
    }

    public function clear(Response $response): Response
    {
        // Each cookie must be expired on the SAME path it was set on, or the browser keeps it.
        $response->headers->setCookie($this->cookie($this->config->accessName, '', 1, $this->config->path));
        $response->headers->setCookie($this->cookie($this->config->refreshName, '', 1, $this->config->refreshPath));

        return $response;
    }

    private function cookie(string $name, string $value, int $expiresAt, string $path): Cookie
    {
        return Cookie::create(
            name: $name,
            value: $value,
            expire: $expiresAt,
            path: $path,
            domain: $this->config->domain,
            secure: $this->config->secure,
            httpOnly: true,
            raw: false,
            sameSite: $this->config->sameSite,
        );
    }
}
