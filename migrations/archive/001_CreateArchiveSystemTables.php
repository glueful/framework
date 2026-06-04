<?php

declare(strict_types=1);

namespace Glueful\Migrations\Archive;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Archive system tables — core `src/Services/Archive` schema. Owned by framework core; registered
 * only when `archive.database_schema` is true (opt-in, default off).
 */
class CreateArchiveSystemTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('archive_registry')) {
            $schema->createTable('archive_registry', function ($table) {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('table_name', 64);
                $table->date('archive_date');
                $table->dateTime('period_start');
                $table->dateTime('period_end');
                $table->integer('record_count')->unsigned();
                $table->string('file_path', 500);
                $table->bigInteger('file_size')->unsigned();
                $table->enum('compression_type', ['gzip', 'bzip2', 'lz4'], 'gzip');
                $table->boolean('encryption_enabled')->default(true);
                $table->string('checksum_sha256', 64);
                $table->enum('status', ['creating', 'completed', 'verified', 'corrupted', 'failed'], 'creating');
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->index('table_name');
                $table->index('archive_date');
                $table->index('status');
                $table->index('period_start');
                $table->index('period_end');
            });
        }

        if (!$schema->hasTable('archive_search_index')) {
            $schema->createTable('archive_search_index', function ($table) {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('archive_uuid', 12);
                $table->string('entity_type', 50);
                $table->string('entity_value', 255);
                $table->integer('record_count')->unsigned();
                $table->dateTime('first_occurrence');
                $table->dateTime('last_occurrence');
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

                $table->index('archive_uuid');
                $table->index('entity_type');
                $table->index('entity_value');
                $table->index('first_occurrence');
                $table->index('last_occurrence');

                // Intra-capability FK (both tables are archive-owned).
                $table->foreign('archive_uuid')
                    ->references('uuid')
                    ->on('archive_registry')
                    ->cascadeOnDelete();
            });
        }

        if (!$schema->hasTable('archive_table_stats')) {
            $schema->createTable('archive_table_stats', function ($table) {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('table_name', 64);
                $table->bigInteger('current_size_bytes')->unsigned()->default(0);
                $table->integer('current_row_count')->unsigned()->default(0);
                $table->date('last_archive_date')->nullable();
                $table->date('next_archive_date')->nullable();
                $table->integer('archive_threshold_rows')->unsigned()->default(100000);
                $table->integer('archive_threshold_days')->unsigned()->default(30);
                $table->boolean('auto_archive_enabled')->default(true);
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('table_name');
                $table->index('last_archive_date');
                $table->index('next_archive_date');
                $table->index('auto_archive_enabled');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('archive_search_index');
        $schema->dropTableIfExists('archive_table_stats');
        $schema->dropTableIfExists('archive_registry');
    }

    public function getDescription(): string
    {
        return 'Creates archive system tables (registry, search index, table stats)';
    }
}
