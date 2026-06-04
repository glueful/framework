<?php

declare(strict_types=1);

namespace Glueful\Migrations\Uploads;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Blobs table — DB metadata for the core uploads/storage subsystem (`src/Uploader`, `src/Storage`).
 * Owned by framework core; registered when `uploads.enabled` is true. `created_by` is an external
 * principal id (the uploader's uuid lives in the user store) — indexed, NO cross-package FK (§2).
 */
class CreateBlobsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('blobs')) {
            return;
        }

        $schema->createTable('blobs', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('mime_type', 127);
            $table->bigInteger('size');
            $table->string('url', 2048);
            $table->string('storage_type', 20)->default('local');
            $table->string('visibility', 10)->default('private'); // public or private
            $table->string('status', 20)->default('active');
            $table->string('created_by', 12);
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            $table->unique('uuid');
            $table->index('created_by');
            $table->index('visibility');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('blobs');
    }

    public function getDescription(): string
    {
        return 'Creates the blobs table (uploads/storage metadata)';
    }
}
