<?php

declare(strict_types=1);

namespace Glueful\Events\Database;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Entity Deleted Event
 *
 * Dispatched after an entity is deleted from the database (affected rows > 0).
 * Carries the PRE-DELETE record so consumers can derive identity/labels.
 *
 * @package Glueful\Events\Database
 */
class EntityDeletedEvent extends BaseEvent
{
    /**
     * @param array<string, mixed>|object $originalData The record as it was before deletion
     * @param string $table Database table name
     * @param array<string, mixed> $metadata Additional metadata
     */
    public function __construct(
        private readonly array|object $originalData,
        private readonly string $table,
        array $metadata = []
    ) {
        parent::__construct();
        foreach ($metadata as $key => $value) {
            $this->setMetadata($key, $value);
        }
    }

    /** @return array<string, mixed>|object */
    public function getEntity(): array|object
    {
        return $this->originalData;
    }

    /** @return array<string, mixed>|object */
    public function getOriginalData(): array|object
    {
        return $this->originalData;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getEntityId(): mixed
    {
        if (is_array($this->originalData)) {
            return $this->originalData['id'] ?? $this->originalData['uuid'] ?? null;
        }
        if (is_object($this->originalData)) {
            return $this->originalData->id ?? $this->originalData->uuid ?? null;
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
