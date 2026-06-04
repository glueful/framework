<?php

declare(strict_types=1);

namespace Glueful\Migrations\Scheduler;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Scheduler tables — core `src/Scheduler` schema (scheduled_jobs + job_executions). Owned by
 * framework core; registered when `schedule.database_store` is true. Replaces the lazy DDL that
 * JobScheduler used to run in its constructor.
 */
class CreateScheduledJobsTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('scheduled_jobs')) {
            $schema->createTable('scheduled_jobs', function ($table) {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('name', 255);
                $table->string('schedule', 100);
                $table->string('handler_class', 255);
                $table->json('parameters')->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->timestamp('last_run')->nullable();
                $table->timestamp('next_run')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->index('name');
                $table->index('next_run');
                $table->index('is_enabled');
            });
        }

        if (!$schema->hasTable('job_executions')) {
            $schema->createTable('job_executions', function ($table) {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('job_uuid', 12);
                $table->enum('status', ['success', 'failure', 'running']);
                $table->timestamp('started_at');
                $table->timestamp('completed_at')->nullable();
                $table->text('result')->nullable();
                $table->text('error_message')->nullable();
                $table->float('execution_time')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

                $table->unique('uuid');
                $table->index('job_uuid');
                $table->index('status');
                $table->index('started_at');

                // Intra-capability FK (both tables are scheduler-owned).
                $table->foreign('job_uuid')
                    ->references('uuid')
                    ->on('scheduled_jobs')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('job_executions');
        $schema->dropTableIfExists('scheduled_jobs');
    }

    public function getDescription(): string
    {
        return 'Creates scheduler tables (scheduled_jobs, job_executions)';
    }
}
