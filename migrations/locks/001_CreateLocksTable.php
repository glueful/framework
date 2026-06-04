<?php

declare(strict_types=1);

namespace Glueful\Migrations\Locks;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Locks table — core `src/Lock` schema for the database lock driver (Symfony Lock store).
 * Owned by framework core; registered only when `lock.default === 'database'` (default is `file`).
 */
class CreateLocksTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('locks')) {
            return;
        }

        $schema->createTable('locks', function ($table) {
            $table->string('key_id', 255)->primary();
            $table->string('token', 255);
            $table->integer('expiration')->unsigned();

            $table->index('expiration');
            $table->index('token');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('locks');
    }

    public function getDescription(): string
    {
        return 'Creates locks table for the database lock driver (Symfony Lock store)';
    }
}
