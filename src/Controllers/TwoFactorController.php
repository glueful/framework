<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Auth\LoginResponseShaper;
use Glueful\Auth\TwoFactor\Exceptions\InvalidChallengeTokenException;
use Glueful\Auth\TwoFactor\Exceptions\InvalidTwoFactorCodeException;
use Glueful\Auth\TwoFactor\Exceptions\TwoFactorNotEnabledException;
use Glueful\Auth\TwoFactor\Exceptions\TwoFactorReelevationRequiredException;
use Glueful\Auth\TwoFactor\TwoFactorService;
use Glueful\Auth\JWTService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\RequestHelper;
use Glueful\Http\Exceptions\Domain\AuthenticationException;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Email-PIN 2FA endpoints: enable, verify, disable.
 *
 * - enable  (auth required): emails a PIN + returns a challenge_token.
 * - verify  (no auth — challenge_token authenticates): completes enrollment
 *           or completes login (full shaped login response).
 * - disable (auth required + recent 2FA verification on the current session).
 *
 * All actions return the framework Response envelope (Glueful\Http\Response).
 */
final class TwoFactorController extends BaseController
{
    public function __construct(
        ApplicationContext $context,
        private TwoFactorService $twoFactor,
        private LoginResponseShaper $loginResponseShaper,
    ) {
        parent::__construct($context);
    }

    public function enable(Request $request): Response
    {
        $userUuid = $this->userContext->getUserUuid();
        $user = $this->userContext->getUser();
        if ($userUuid === null || $user === null) {
            throw new AuthenticationException('Authentication required');
        }

        $challenge = $this->twoFactor->beginEnable($userUuid, (string) $user->email());

        return Response::success([
            'challenge_token' => $challenge['token'],
            'expires_in' => $challenge['expires_in'],
            'delivered_to' => $challenge['delivered_to'],
        ], 'Two-factor code sent');
    }

    public function verify(Request $request): Response
    {
        $payload = RequestHelper::getRequestData($request);
        $token = (string) ($payload['challenge_token'] ?? '');
        $code = (string) ($payload['code'] ?? '');

        try {
            $result = $this->twoFactor->verify($token, $code);
        } catch (
            InvalidChallengeTokenException
            | InvalidTwoFactorCodeException
            | TwoFactorNotEnabledException $e
        ) {
            // Internal exceptions distinguish failure modes for logging/tests.
            // On the wire, collapse to a single 401 when generic errors are on (default).
            if ((bool) config($this->getContext(), 'security.auth.generic_error_responses', true)) {
                throw new AuthenticationException('Invalid or expired verification');
            }
            throw $e;
        }

        if (($result['purpose'] ?? null) === 'login') {
            /** @var array<string, mixed> $session */
            $session = $result['session'] ?? [];
            return $this->loginResponseShaper->shape($request, $session);
        }

        // 'enabled' purpose → enrollment complete.
        return Response::success(null, 'Two-factor authentication enabled');
    }

    public function disable(Request $request): Response
    {
        $userUuid = $this->userContext->getUserUuid();
        if ($userUuid === null) {
            throw new AuthenticationException('Authentication required');
        }

        // Freshness lives in a session-scoped cache marker keyed by the issued
        // session's sid (TokenManager hard-codes the access-token payload to
        // sub/sid/ver, so a freshness claim can't ride on the token). Reading by
        // sid — not user_uuid — blocks a stolen token from a different session.
        $sid = $this->currentSessionId();
        if ($sid === '' || !$this->twoFactor->hasFreshVerification($sid)) {
            throw new TwoFactorReelevationRequiredException();
        }

        $this->twoFactor->disable($userUuid, $sid);
        return Response::success(null, 'Two-factor authentication disabled');
    }

    /**
     * Pull the `sid` claim out of the current request's access token. The token is
     * taken from RequestUserContext (which already extracted the bearer token during
     * initialize()) and decoded; the `sid` matches the freshness marker written by
     * TwoFactorService::verify().
     */
    private function currentSessionId(): string
    {
        $token = $this->userContext->getToken();
        if ($token === null || $token === '') {
            return '';
        }
        $claims = JWTService::decode($token);
        if (!is_array($claims)) {
            return '';
        }
        return (string) ($claims['sid'] ?? '');
    }
}
