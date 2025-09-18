<?php

declare(strict_types=1);

namespace Glueful\Events\Database;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Entity Created Event
 *
 * Dispatched when a new entity is created in the database.
 * Used for cache invalidation, audit logging, and notifications.
 *
 * @package Glueful\Events\Database
 */
class EntityCreatedEvent extends BaseEvent
{
    /**
     * @param mixed $entity The created entity/data
     * @param string $table Database table name
     * @param array<string, mixed> $metadata Additional metadata
     */
    public function __construct(
        private readonly mixed $entity,
        private readonly string $table,
        array $metadata = []
    ) {
        parent::__construct();

        foreach ($metadata as $key => $value) {
            $this->setMetadata($key, $value);
        }
    }

    public function getEntity(): mixed
    {
        return $this->entity;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getEntityId(): mixed
    {
        if (is_array($this->entity)) {
            return $this->entity['id'] ?? $this->entity['uuid'] ?? null;
        }

        if (is_object($this->entity)) {
            return $this->entity->id ?? $this->entity->uuid ?? null;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function getCacheTags(): array
    {
        $tags = [$this->table];

        $entityId = $this->getEntityId();
        if ($entityId !== null) {
            $tags[] = $this->table . ':' . $entityId;
        }

        return array_merge($tags, $this->getMetadata('cache_tags') ?? []);
    }

    public function isUserRelated(): bool
    {
        return in_array($this->table, ['users', 'user_sessions', 'user_preferences'], true);
    }
}
