<?php

declare(strict_types=1);

namespace Glueful\Events\Auth;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Dispatched before returning the login response.
 *
 * Allows listeners to add fields to the response (e.g., organization context)
 * by updating the response map via setResponse()/mergeResponse().
 */
final class LoginResponseBuildingEvent extends BaseEvent
{
    /** @var array<string, mixed> */
    private array $response;

    /**
     * @param array<string, mixed> $tokens Token pair and expiry (access_token, refresh_token, expires_in, token_type)
     * @param array<string, mixed> $user OIDC user fields
     * @param array<string, mixed> $response Mutable response map to be returned
     */
    public function __construct(
        private readonly array $tokens,
        private readonly array $user,
        array $response
    ) {
        parent::__construct();
        $this->response = $response;
    }

    /** @return array<string, mixed> */
    public function getTokens(): array
    {
        return $this->tokens;
    }

    /** @return array<string, mixed> */
    public function getUser(): array
    {
        return $this->user;
    }

    /** @return array<string, mixed> */
    public function getResponse(): array
    {
        return $this->response;
    }

    /**
     * Replace the response completely.
     * @param array<string, mixed> $response
     */
    public function setResponse(array $response): void
    {
        $this->response = $response;
    }

    /**
     * Merge into the response recursively.
     * @param array<string, mixed> $patch
     */
    public function mergeResponse(array $patch): void
    {
        $this->response = array_replace_recursive($this->response, $patch);
    }
}
