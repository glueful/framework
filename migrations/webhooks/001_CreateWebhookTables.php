<?php

declare(strict_types=1);

namespace Glueful\Migrations\Webhooks;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Webhook tables — core `src/Api/Webhooks` schema (subscriptions + delivery records). Owned by
 * framework core. Replaces the lazy DDL that WebhookDispatcher::dispatch() used to run on first
 * use; the hasTable() guards let databases where that lazy path already created the tables
 * migrate cleanly.
 */
class CreateWebhookTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('webhook_subscriptions')) {
            $schema->createTable('webhook_subscriptions', function ($table) {
                $table->bigInteger('id')->unsigned()->primary()->autoIncrement();
                $table->string('uuid', 32)->unique();
                $table->string('url', 2048);
                $table->json('events');
                $table->string('secret', 255);
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

                $table->index('is_active');
            });
        }

        if (!$schema->hasTable('webhook_deliveries')) {
            $schema->createTable('webhook_deliveries', function ($table) {
                $table->bigInteger('id')->unsigned()->primary()->autoIncrement();
                $table->string('uuid', 32)->unique();
                $table->bigInteger('subscription_id')->unsigned();
                $table->string('event', 255);
                $table->json('payload');
                $table->string('status', 20)->default('pending');
                $table->integer('attempts')->unsigned()->default(0);
                $table->integer('response_code')->unsigned()->nullable();
                $table->text('response_body')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('next_retry_at')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

                $table->index('subscription_id');
                $table->index('status');
                $table->index('next_retry_at');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('webhook_deliveries');
        $schema->dropTableIfExists('webhook_subscriptions');
    }

    public function getDescription(): string
    {
        return 'Creates webhook tables (subscriptions, deliveries) for outbound webhooks';
    }
}
