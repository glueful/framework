<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Core-owned record of extension enable/disable operations (schema policy spec B5). Lives in core
 * because the target extension's schema does not exist yet at enable time; the CLI, the framework
 * HTTP controller, and app admin controllers all drive the same executor over these rows.
 */
class CreateExtensionOperationsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('extension_operations')) {
            return;
        }

        $schema->createTable('extension_operations', function ($table) {
            $table->id();
            $table->string('package', 191);
            $table->string('operation', 16);   // enable | disable
            $table->string('step', 64);
            $table->string('status', 32);      // running | succeeded | failed | manual_repair | enabled_cache_stale
            $table->string('actor', 191);
            $table->string('failed_migration', 255)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

            $table->index('package');
            $table->index('status');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // The schema policy never drops tables on rollback of operational state.
    }

    public function getDescription(): string
    {
        return 'Core-owned extension enable/disable operation records for the schema executor';
    }
}
