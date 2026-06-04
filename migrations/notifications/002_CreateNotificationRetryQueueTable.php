<?php

declare(strict_types=1);

namespace Glueful\Migrations\Notifications;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * notification_retry_queue — previously created only at runtime by NotificationRetryService; now a
 * first-class migration. Owned by framework core; registered with the other notification tables
 * when `notifications.database_store` is true.
 */
class CreateNotificationRetryQueueTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('notification_retry_queue')) {
            return;
        }

        $schema->createTable('notification_retry_queue', function ($table) {
            $table->integer('id')->primary()->autoIncrement();
            $table->string('notification_id', 255);
            $table->string('notifiable_type', 100);
            $table->string('notifiable_id', 255);
            $table->string('channel', 50);
            $table->integer('retry_count')->default(1);
            $table->timestamp('retry_at');
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            $table->unique(['notification_id', 'channel']);
            $table->index('retry_at');
            $table->index('notification_id');
            $table->index(['notifiable_type', 'notifiable_id']);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('notification_retry_queue');
    }

    public function getDescription(): string
    {
        return 'Creates the notification_retry_queue table (was previously created at runtime)';
    }
}
