<?php

declare(strict_types=1);

namespace Glueful\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * API keys — core security-spine schema. The code that reads/writes this table
 * (ApiKeyService, ApiKeyAuthenticationProvider) lives in framework core, so core owns the
 * migration. user_uuid is an INDEXED uuid with NO FK — an external principal id (§2); existence
 * is validated in the service layer. (Renamed from the skeleton's user_id for naming consistency
 * with the rest of the identity schema.)
 */
class CreateApiKeysTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('api_keys')) {
            return;
        }

        $schema->createTable('api_keys', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('user_uuid', 12);
            $table->string('name', 255);
            $table->string('key_prefix', 24);
            $table->string('key_hash', 64);
            $table->text('scopes')->nullable();
            $table->text('allowed_ips')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->bigInteger('rotated_from_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

            $table->unique('uuid');
            $table->unique('key_hash');
            $table->index('user_uuid'); // indexed only — external principal id, no FK (§2)
            $table->index('key_prefix');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('api_keys');
    }

    public function getDescription(): string
    {
        return 'Create the api_keys table (core; user_uuid indexed, no FK — external principal id).';
    }
}
