<?php

declare(strict_types=1);

namespace Glueful\Migrations\Notifications;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Notification system tables — core `src/Notifications` schema. Owned by framework core;
 * registered when `notifications.database_store` is true.
 */
class CreateNotificationSystemTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('notifications')) {
            $schema->createTable('notifications', function ($table) {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('type', 100);
                $table->string('subject', 255);
                $table->string('idempotency_key', 191)->nullable();
                $table->json('data')->nullable();
                $table->string('priority', 20)->default('normal');
                $table->string('notifiable_type', 100);
                $table->string('notifiable_id', 255);
                $table->timestamp('read_at')->nullable();
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->index('notifiable_type');
                $table->index('notifiable_id');
                $table->index('type');
                $table->index('read_at');
                $table->index('scheduled_at');
                $table->index(
                    ['notifiable_type', 'notifiable_id', 'type', 'idempotency_key', 'created_at'],
                    'idx_notifications_idempotency_lookup'
                );
            });
        }

        if (!$schema->hasTable('notification_deliveries')) {
            $schema->createTable('notification_deliveries', function ($table) {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('notification_uuid', 12);
                $table->string('channel', 100);
                $table->string('status', 20)->default('pending'); // pending|sent|failed
                $table->integer('attempt_count')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('last_attempt_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique(['notification_uuid', 'channel'], 'unique_notification_delivery_channel');
                $table->index('notification_uuid');
                $table->index('channel');
                $table->index('status');
                $table->index('sent_at');
            });
        }

        if (!$schema->hasTable('notification_preferences')) {
            $schema->createTable('notification_preferences', function ($table) {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('notifiable_type', 100);
                $table->string('notifiable_id', 255);
                $table->string('notification_type', 100);
                $table->json('channels')->nullable();
                $table->boolean('enabled')->default(true);
                $table->json('settings')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->index('notifiable_type');
                $table->index('notifiable_id');
                $table->index('notification_type');
                $table->unique(
                    ['notifiable_type', 'notifiable_id', 'notification_type'],
                    'unique_notification_pref'
                );
            });
        }

        if (!$schema->hasTable('notification_templates')) {
            $schema->createTable('notification_templates', function ($table) {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('name', 255);
                $table->string('notification_type', 100);
                $table->string('channel', 100);
                $table->text('content');
                $table->json('parameters')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->index('notification_type');
                $table->index('channel');
                $table->unique(['notification_type', 'channel', 'name'], 'unique_notification_template');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('notification_templates');
        $schema->dropTableIfExists('notification_preferences');
        $schema->dropTableIfExists('notification_deliveries');
        $schema->dropTableIfExists('notifications');
    }

    public function getDescription(): string
    {
        return 'Creates notification system tables (notifications, deliveries, preferences, templates)';
    }
}
