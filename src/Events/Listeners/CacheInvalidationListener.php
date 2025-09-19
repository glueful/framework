<?php

declare(strict_types=1);

namespace Glueful\Events\Listeners;

use Glueful\Events\Auth\SessionCreatedEvent;
use Glueful\Events\Auth\SessionDestroyedEvent;
use Glueful\Events\Database\EntityCreatedEvent;
use Glueful\Events\Database\EntityUpdatedEvent;
use Glueful\Events\Cache\CacheInvalidatedEvent;
use Glueful\Cache\CacheStore;
use Glueful\Events\Event;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Cache Invalidation Event Listener
 *
 * Automatically invalidates cache when data changes.
 * Uses intelligent cache key patterns to invalidate related cache entries.
 *
 * @package Glueful\Events\Listeners
 */
class CacheInvalidationListener implements EventSubscriberInterface
{
    /** @var array<string, array<int, string>> Cache invalidation patterns by table */
    private array $invalidationPatterns = [
        'users' => [
            'user:{id}',
            'user_permissions:{id}',
            'active_users',
            'user_count'
        ],
        'sessions' => [
            'session_token:*',
            'user_sessions:{user_id}',
            'active_sessions'
        ],
        'permissions' => [
            'permission:{id}',
            'user_permissions:*',
            'role_permissions:*'
        ],
        'roles' => [
            'role:{id}',
            'role_permissions:*',
            'user_roles:*'
        ]
    ];

    /**
     * @param CacheStore<mixed> $cache
     */
    public function __construct(
        private CacheStore $cache
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            EntityCreatedEvent::class => 'onEntityCreated',
            EntityUpdatedEvent::class => 'onEntityUpdated',
            SessionCreatedEvent::class => 'onSessionCreated',
            SessionDestroyedEvent::class => 'onSessionDestroyed',
        ];
    }

    public function onEntityCreated(EntityCreatedEvent $event): void
    {
        $this->invalidateCacheForEntity($event->getTable(), (string)$event->getEntityId(), 'create');
    }

    public function onEntityUpdated(EntityUpdatedEvent $event): void
    {
        $this->invalidateCacheForEntity($event->getTable(), (string)$event->getEntityId(), 'update');
    }

    public function onSessionCreated(SessionCreatedEvent $event): void
    {
        $userUuid = $event->getUserUuid();
        if ($userUuid !== null) {
            $invalidatedKeys = [];
            $keysToInvalidate = [
                "user_data:{$userUuid}",
                "user_sessions:{$userUuid}",
                'active_sessions'
            ];
            foreach ($keysToInvalidate as $key) {
                if ($this->cache->delete($key)) {
                    $invalidatedKeys[] = $key;
                }
            }
            $this->dispatchCacheInvalidationEvent($invalidatedKeys, 'session_created', [
                'user_uuid' => $userUuid,
                'session_data' => $event->getSessionData()
            ]);
        }
    }

    public function onSessionDestroyed(SessionDestroyedEvent $event): void
    {
        $userUuid = $event->getUserUuid();
        if ($userUuid !== null) {
            $invalidatedKeys = [];
            $keysToInvalidate = [
                "user_sessions:{$userUuid}",
                'active_sessions'
            ];
            foreach ($keysToInvalidate as $key) {
                if ($this->cache->delete($key)) {
                    $invalidatedKeys[] = $key;
                }
            }
            $this->dispatchCacheInvalidationEvent($invalidatedKeys, 'session_destroyed', [
                'user_uuid' => $userUuid
            ]);
        }
    }

    private function invalidateCacheForEntity(string $table, string $entityId, string $operation): void
    {
        $patterns = $this->invalidationPatterns[$table] ?? [];
        $patterns[] = "{$table}:{$entityId}";
        $patterns[] = "{$table}_list";
        $patterns[] = "{$table}_count";

        $invalidatedKeys = [];
        foreach ($patterns as $pattern) {
            $cacheKeys = $this->resolveCachePattern($pattern, $entityId);
            foreach ($cacheKeys as $cacheKey) {
                if ($this->cache->delete($cacheKey)) {
                    $invalidatedKeys[] = $cacheKey;
                }
            }
        }

        $this->dispatchCacheInvalidationEvent($invalidatedKeys, "entity_{$operation}", [
            'table' => $table,
            'entity_id' => $entityId,
            'operation' => $operation
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function resolveCachePattern(string $pattern, string $entityId): array
    {
        $resolvedPattern = str_replace(['{id}', '{user_id}'], $entityId, $pattern);
        if (str_contains($resolvedPattern, '*')) {
            $this->cache->deletePattern($resolvedPattern);
            return [$resolvedPattern];
        }
        return [$resolvedPattern];
    }

    /**
     * @return array<int, string>
     */
    private function getKeysMatchingPattern(string $pattern): array
    {
        $keys = [];
        $regex = str_replace(['*', ':'], ['.*', '\\:'], $pattern);
        $regex = "/^{$regex}$/";
        foreach ($this->cache->getAllKeys() as $key) {
            if (preg_match($regex, $key)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * @param array<int, string> $invalidatedKeys
     * @param string $reason
     * @param array<string, mixed> $metadata
     */
    private function dispatchCacheInvalidationEvent(array $invalidatedKeys, string $reason, array $metadata = []): void
    {
        if (count($invalidatedKeys) > 0) {
            $event = new CacheInvalidatedEvent($invalidatedKeys, [], $reason, array_merge($metadata, [
                'timestamp' => time(),
                'count' => count($invalidatedKeys)
            ]));
            Event::dispatch($event);
        }
    }

    /**
     * @param array<string> $patterns
     */
    public function addInvalidationPatterns(string $table, array $patterns): void
    {
        if (!isset($this->invalidationPatterns[$table])) {
            $this->invalidationPatterns[$table] = [];
        }
        $this->invalidationPatterns[$table] = array_merge(
            $this->invalidationPatterns[$table],
            $patterns
        );
    }

    /**
     * @return array<int, string>
     */
    public function getInvalidationPatterns(string $table): array
    {
        return $this->invalidationPatterns[$table] ?? [];
    }

    public function clearInvalidationPatterns(): void
    {
        $this->invalidationPatterns = [];
    }
}
