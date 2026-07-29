<?php

declare(strict_types=1);

namespace Glueful\Auth\Session;

/**
 * An unfinished login awaiting second-factor verification.
 *
 * Deliberately carries no access or refresh token: at this point none exists.
 */
final class TwoFactorChallenge
{
    private function __construct(
        public readonly string $token,
        public readonly int $expiresIn,
        public readonly ?string $deliveredTo,
    ) {
    }

    /** @param array<string,mixed> $challenge */
    public static function fromArray(array $challenge): self
    {
        $token = (string) ($challenge['token'] ?? '');
        if ($token === '') {
            throw new \InvalidArgumentException('Two-factor challenge is missing its token.');
        }

        $deliveredTo = $challenge['delivered_to'] ?? null;

        return new self(
            token: $token,
            expiresIn: (int) ($challenge['expires_in'] ?? 0),
            deliveredTo: $deliveredTo === null ? null : (string) $deliveredTo,
        );
    }
}
