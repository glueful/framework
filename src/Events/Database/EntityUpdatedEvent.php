<?php

declare(strict_types=1);

namespace Glueful\Events\Database;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Entity Updated Event
 *
 * Dispatched when an existing entity is updated in the database.
 * Used for cache invalidation, audit logging, and notifications.
 */
class EntityUpdatedEvent extends BaseEvent
{
    /**
     * @param array<string, mixed>|object $entityData The updated entity/data (including ID)
     * @param string $table Database table name
     * @param array<string, mixed> $changes Changes that were applied
     * @param array<string, mixed> $metadata Additional metadata
     */
    public function __construct(
        private readonly array|object $entityData,
        private readonly string $table,
        private readonly array $changes = [],
        array $metadata = []
    ) {
        parent::__construct();

        foreach ($metadata as $key => $value) {
            $this->setMetadata($key, $value);
        }
    }

    /**
     * @return array<string, mixed>|object
     */
    public function getEntity(): array|object
    {
        return $this->entityData;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * @return array<string, mixed>
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    public function getEntityId(): mixed
    {
        if (is_array($this->entityData)) {
            return $this->entityData['id'] ?? $this->entityData['uuid'] ?? null;
        }
        if (is_object($this->entityData)) {
            return $this->entityData->id ?? $this->entityData->uuid ?? null;
        }
        return null;
    }
}
