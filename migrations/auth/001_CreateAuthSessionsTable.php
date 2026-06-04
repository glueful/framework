<?php

declare(strict_types=1);

namespace Glueful\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Auth sessions — core security-spine schema. The code that reads/writes this table
 * (SessionStore, SessionRepository, TokenManager) lives in framework core, so core owns and
 * versions the migration.
 *
 * user_uuid is an INDEXED uuid with NO FK — it is an external principal id (the user store is a
 * separate package, e.g. glueful/users); existence is validated in the auth layer, not via a
 * cross-package FK constraint (design §2). Includes session_version (access-token invalidation).
 */
class CreateAuthSessionsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('auth_sessions')) {
            return;
        }

        $schema->createTable('auth_sessions', function ($table) {
            $table->bigInteger('id')->unsigned()->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('user_uuid', 12);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->integer('session_version')->default(1);
            $table->string('status', 20)->default('active');
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
            $table->text('provider')->default('jwt');
            $table->boolean('remember_me')->default(false);

            $table->unique('uuid');
            $table->index('user_uuid'); // indexed only — external principal id, no FK (§2)
            $table->index('status');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('auth_sessions');
    }

    public function getDescription(): string
    {
        return 'Create the auth_sessions table (core; user_uuid indexed, no FK; session_version).';
    }
}
