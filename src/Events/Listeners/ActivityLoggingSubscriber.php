<?php

declare(strict_types=1);

namespace Glueful\Events\Listeners;

use Glueful\Events\EventSubscriberInterface;
use Glueful\Events\Auth\AuthenticationFailedEvent;
use Glueful\Events\Auth\SessionCreatedEvent;
use Glueful\Events\Auth\SessionDestroyedEvent;
use Glueful\Events\Auth\RateLimitExceededEvent;
use Glueful\Events\Security\AdminSecurityViolationEvent;
use Glueful\Logging\LogManager;

/**
 * Activity Logging Event Subscriber
 *
 * Automatically logs auth and security events to the activity_logs table
 * using channel-based logging for proper retention policies.
 *
 * Channels used:
 * - 'auth': Login, logout, session events (365 days retention)
 * - 'security': Security violations, rate limits (365 days retention)
 *
 * All logs include structured audit fields (action, actor_id, resource_type)
 * that are auto-extracted by DatabaseLogHandler for efficient querying.
 *
 * @package Glueful\Events\Listeners
 */
class ActivityLoggingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LogManager $logger
    ) {
    }

    /**
     * @return array<string, string|array{0:string,1?:int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // Auth events -> 'auth' channel
            AuthenticationFailedEvent::class => 'onAuthenticationFailed',
            SessionCreatedEvent::class => 'onSessionCreated',
            SessionDestroyedEvent::class => 'onSessionDestroyed',

            // Security events -> 'security' channel
            AdminSecurityViolationEvent::class => 'onSecurityViolation',
            RateLimitExceededEvent::class => 'onRateLimitExceeded',
        ];
    }

    /**
     * Log failed authentication attempts to auth channel
     */
    public function onAuthenticationFailed(AuthenticationFailedEvent $event): void
    {
        $this->logger->channel('auth')->warning('Authentication failed', [
            // Structured audit fields (auto-extracted by DatabaseLogHandler)
            'action' => 'auth_failed',
            'resource_type' => 'auth',

            // Event details
            'username' => $event->getUsername(),
            'reason' => $event->getReason(),
            'client_ip' => $event->getClientIp(),
            'user_agent' => $event->getUserAgent(),
            'suspicious' => $event->isSuspicious(),
            'event_id' => $event->getEventId(),
        ]);
    }

    /**
     * Log successful session creation to auth channel
     */
    public function onSessionCreated(SessionCreatedEvent $event): void
    {
        $userUuid = $event->getUserUuid();

        $this->logger->channel('auth')->info('User logged in', [
            // Structured audit fields
            'action' => 'login',
            'actor_id' => $userUuid,
            'resource_type' => 'sessions',

            // Event details
            'username' => $event->getUsername(),
            'event_id' => $event->getEventId(),
        ]);
    }

    /**
     * Log session destruction to auth channel
     */
    public function onSessionDestroyed(SessionDestroyedEvent $event): void
    {
        $userUuid = $event->getUserUuid();

        $this->logger->channel('auth')->info('User logged out', [
            // Structured audit fields
            'action' => $this->mapLogoutReason($event->getReason()),
            'actor_id' => $userUuid,
            'resource_type' => 'sessions',

            // Event details
            'reason' => $event->getReason(),
            'event_id' => $event->getEventId(),
        ]);
    }

    /**
     * Log security violations to security channel
     */
    public function onSecurityViolation(AdminSecurityViolationEvent $event): void
    {
        $this->logger->channel('security')->warning('Security violation', [
            // Structured audit fields
            'action' => 'security_violation',
            'actor_id' => $event->userUuid,
            'resource_type' => 'admin',

            // Event details
            'violation_type' => $event->violationType,
            'message' => $event->message,
            'request_uri' => $event->request->getRequestUri(),
            'request_method' => $event->request->getMethod(),
            'client_ip' => $event->request->getClientIp(),
            'event_id' => $event->getEventId(),
        ]);
    }

    /**
     * Log rate limit violations to security channel
     */
    public function onRateLimitExceeded(RateLimitExceededEvent $event): void
    {
        $context = [
            // Structured audit fields
            'action' => 'rate_limit_exceeded',
            'resource_type' => 'rate_limit',

            // Event details
            'rule' => $event->getRule(),
            'client_ip' => $event->getClientIp(),
            'current_count' => $event->getCurrentCount(),
            'limit' => $event->getLimit(),
            'window_seconds' => $event->getWindowSeconds(),
            'excess_percentage' => round($event->getExcessPercentage(), 1),
            'severe' => $event->isSevereViolation(),
            'event_id' => $event->getEventId(),
        ];

        $securityChannel = $this->logger->channel('security');

        if ($event->isSevereViolation()) {
            $securityChannel->error('Rate limit exceeded (severe)', $context);
        } else {
            $securityChannel->warning('Rate limit exceeded', $context);
        }
    }

    /**
     * Map session destruction reason to appropriate action name
     */
    private function mapLogoutReason(string $reason): string
    {
        return match ($reason) {
            'logout' => 'logout',
            'expired' => 'session_expired',
            'revoked', 'admin_revoked', 'security_revoked' => 'session_revoked',
            default => 'logout',
        };
    }
}
