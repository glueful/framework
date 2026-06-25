<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Events\Database;

use Glueful\Events\Database\EntityDeletedEvent;
use PHPUnit\Framework\TestCase;

final class EntityDeletedEventTest extends TestCase
{
    public function test_get_table_returns_table_name(): void
    {
        $event = new EntityDeletedEvent(['uuid' => 'abc'], 'posts');

        $this->assertSame('posts', $event->getTable());
    }

    public function test_get_entity_id_from_uuid(): void
    {
        $event = new EntityDeletedEvent(['uuid' => 'uuid-123', 'name' => 'x'], 'posts');

        $this->assertSame('uuid-123', $event->getEntityId());
    }

    public function test_get_entity_id_prefers_id_over_uuid(): void
    {
        $event = new EntityDeletedEvent(['id' => 42, 'uuid' => 'uuid-123'], 'posts');

        $this->assertSame(42, $event->getEntityId());
    }

    public function test_get_entity_id_from_object(): void
    {
        $record = (object) ['uuid' => 'obj-uuid'];
        $event = new EntityDeletedEvent($record, 'posts');

        $this->assertSame('obj-uuid', $event->getEntityId());
    }

    public function test_get_entity_id_returns_null_when_no_identity(): void
    {
        $event = new EntityDeletedEvent(['name' => 'no-id'], 'posts');

        $this->assertNull($event->getEntityId());
    }

    public function test_get_entity_and_get_original_data_return_pre_delete_record(): void
    {
        $record = ['uuid' => 'abc', 'name' => 'Original'];
        $event = new EntityDeletedEvent($record, 'posts');

        $this->assertSame($record, $event->getEntity());
        $this->assertSame($record, $event->getOriginalData());
    }

    public function test_metadata_round_trips(): void
    {
        $event = new EntityDeletedEvent(['uuid' => 'abc'], 'posts', [
            'entity_id' => 'abc',
            'operation' => 'delete',
            'affected_rows' => 1,
        ]);

        $this->assertSame('abc', $event->getMetadata('entity_id'));
        $this->assertSame('delete', $event->getMetadata('operation'));
        $this->assertSame(1, $event->getMetadata('affected_rows'));
    }

    public function test_is_user_related_true_for_user_tables(): void
    {
        $this->assertTrue((new EntityDeletedEvent(['uuid' => 'a'], 'users'))->isUserRelated());
        $this->assertTrue((new EntityDeletedEvent(['uuid' => 'a'], 'user_sessions'))->isUserRelated());
        $this->assertTrue((new EntityDeletedEvent(['uuid' => 'a'], 'user_preferences'))->isUserRelated());
    }

    public function test_is_user_related_false_for_other_tables(): void
    {
        $this->assertFalse((new EntityDeletedEvent(['uuid' => 'a'], 'posts'))->isUserRelated());
    }
}
