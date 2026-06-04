<?php

declare(strict_types=1);

namespace Glueful\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Refresh tokens — core security-spine schema (RefreshTokenStore, SessionCleanupTask live in
 * core). session_uuid keeps a hard FK to auth_sessions (both tables are core-owned — intra-package
 * integrity is fine). user_uuid is an INDEXED uuid with NO FK — it is an external principal id
 * (user store is a separate package); existence is validated in the auth layer (§2).
 */
class CreateAuthRefreshTokensTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('auth_refresh_tokens')) {
            return;
        }

        $schema->createTable('auth_refresh_tokens', function ($table) {
            $table->bigInteger('id')->unsigned()->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('session_uuid', 12);
            $table->string('user_uuid', 12);
            $table->string('token_hash', 64);
            $table->string('status', 20)->default('active');
            $table->string('parent_uuid', 12)->nullable();
            $table->string('replaced_by_uuid', 12)->nullable();
            $table->timestamp('issued_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            $table->unique('uuid');
            $table->unique('token_hash');
            $table->index('session_uuid');
            $table->index('user_uuid'); // indexed only — external principal id, no FK (§2)
            $table->index('status');
            $table->index('expires_at');
            $table->index('parent_uuid');

            // Intra-core FK only: refresh tokens belong to a core-owned session.
            $table->foreign('session_uuid')
                ->references('uuid')
                ->on('auth_sessions')
                ->restrictOnDelete();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('auth_refresh_tokens');
    }

    public function getDescription(): string
    {
        return 'Create the auth_refresh_tokens table (core; FK to auth_sessions; user_uuid no FK).';
    }
}
