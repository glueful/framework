<?php

declare(strict_types=1);

namespace Glueful\Migrations\Queue;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Queue system tables — core `src/Queue` schema for the database queue driver. Owned by framework
 * core; registered only when `queue.default === 'database'` (redis/sync drivers need no tables).
 * Replaces the lazy DDL that DatabaseQueue used to run in its constructor.
 */
class CreateQueueSystemTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('queue_jobs')) {
            $schema->createTable('queue_jobs', function ($table) {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12)->unique();
                $table->string('queue', 255)->default('default');
                $table->text('payload');
                $table->integer('attempts')->default(0);
                $table->timestamp('reserved_at')->nullable();
                $table->timestamp('available_at');
                $table->integer('priority')->default(0);
                $table->string('batch_uuid', 12)->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

                $table->index('queue');
                $table->index('reserved_at');
                $table->index('available_at');
                $table->index('priority');
                $table->index('batch_uuid');
                $table->index(['queue', 'reserved_at'], 'idx_queue_reserved');
                $table->index(['queue', 'available_at'], 'idx_queue_available');
                $table->index(['priority', 'available_at'], 'idx_priority_available');
            });
        }

        if (!$schema->hasTable('queue_failed_jobs')) {
            $schema->createTable('queue_failed_jobs', function ($table) {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12)->unique();
                $table->string('connection', 255);
                $table->string('queue', 255);
                $table->text('payload');
                $table->text('exception');
                $table->string('batch_uuid', 12)->nullable();
                $table->timestamp('failed_at')->default('CURRENT_TIMESTAMP');

                $table->index('connection');
                $table->index('queue');
                $table->index('batch_uuid');
                $table->index('failed_at');
                $table->index(['connection', 'queue'], 'idx_failed_connection_queue');
            });
        }

        if (!$schema->hasTable('queue_batches')) {
            $schema->createTable('queue_batches', function ($table) {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12)->unique();
                $table->string('name', 255);
                $table->integer('total_jobs')->default(0);
                $table->integer('pending_jobs')->default(0);
                $table->integer('processed_jobs')->default(0);
                $table->integer('failed_jobs')->default(0);
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->json('options')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->index('name');
                $table->index('cancelled_at');
                $table->index('finished_at');
                $table->index('created_at');
                $table->index(['pending_jobs', 'created_at'], 'idx_batch_pending');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('queue_batches');
        $schema->dropTableIfExists('queue_failed_jobs');
        $schema->dropTableIfExists('queue_jobs');
    }

    public function getDescription(): string
    {
        return 'Creates queue system tables (jobs, failed jobs, batches) for the database queue driver';
    }
}
