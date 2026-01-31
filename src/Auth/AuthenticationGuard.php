<?php

declare(strict_types=1);

namespace Glueful\Auth;

/**
 * Laravel-style authentication guard wrapper
 *
 * Provides familiar auth() interface while using Glueful's authentication system.
 * Enables consistent user access patterns across the framework.
 */
class AuthenticationGuard
{
    private ?object $currentUser = null;
    private bool $userResolved = false;

    public function __construct(
        private AuthenticationService $authService
    ) {
    }

    /**
     * Check if user is authenticated
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get current authenticated user
     */
    public function user(): ?object
    {
        if (!$this->userResolved) {
            $this->currentUser = $this->resolveUser();
            $this->userResolved = true;
        }

        return $this->currentUser;
    }

    /**
     * Get current user ID
     */
    public function id(): mixed
    {
        $user = $this->user();
        if (!$user) {
            return null;
        }

        // Try multiple ID methods
        if (method_exists($user, 'getId')) {
            return $user->getId();
        } elseif (method_exists($user, 'id')) {
            return $user->id();
        } elseif (method_exists($user, 'getUuid')) {
            return $user->getUuid();
        } elseif (method_exists($user, 'uuid')) {
            return $user->uuid();
        }

        return null;
    }

    /**
     * Check if user is guest (not authenticated)
     */
    public function guest(): bool
    {
        return !$this->check();
    }

    /**
     * Resolve current user from various sources
     */
    private function resolveUser(): ?object
    {
        try {
            return $this->authService->getCurrentUser();
        } catch (\Throwable) {
            // Ignore resolution errors
        }

        return null;
    }
}
