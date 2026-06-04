<?php

declare(strict_types=1);

namespace Glueful\Migrations\Metrics;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * API metrics tables — core `src/Services/ApiMetricsService` schema (api_metrics, api_metrics_daily,
 * api_rate_limits). Owned by framework core; registered when `metrics.database_store` is true.
 * Replaces the lazy DDL ApiMetricsService used to run in its constructor.
 */
class CreateApiMetricsTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('api_metrics')) {
            $schema->createTable('api_metrics', function ($table) {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('endpoint', 255);
                $table->string('method', 10);
                $table->decimal('response_time', 10, 2);
                $table->integer('status_code');
                $table->boolean('is_error')->default(false);
                $table->dateTime('timestamp');
                $table->string('ip', 45); // IPv6 compatible

                $table->index('timestamp', 'idx_api_metrics_timestamp');
                $table->index(['endpoint', 'method'], 'idx_api_metrics_endpoint_method');
            });
        }

        if (!$schema->hasTable('api_metrics_daily')) {
            $schema->createTable('api_metrics_daily', function ($table) {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->date('date');
                $table->string('endpoint', 255);
                $table->string('method', 10);
                $table->string('endpoint_key', 266); // endpoint|method
                $table->integer('calls')->default(0);
                $table->decimal('total_response_time', 15, 2)->default(0);
                $table->integer('error_count')->default(0);
                $table->dateTime('last_called')->nullable();

                $table->unique(['date', 'endpoint_key'], 'idx_api_metrics_daily_date_endpoint_key');
                $table->index('date', 'idx_api_metrics_daily_date');
            });
        }

        if (!$schema->hasTable('api_rate_limits')) {
            $schema->createTable('api_rate_limits', function ($table) {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('ip', 45);
                $table->string('endpoint', 255);
                $table->integer('remaining');
                $table->integer('limit');
                $table->dateTime('reset_time');
                $table->decimal('usage_percentage', 5, 2);

                $table->unique(['ip', 'endpoint'], 'idx_api_rate_limits_ip_endpoint');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('api_rate_limits');
        $schema->dropTableIfExists('api_metrics_daily');
        $schema->dropTableIfExists('api_metrics');
    }

    public function getDescription(): string
    {
        return 'Creates API metrics tables (api_metrics, api_metrics_daily, api_rate_limits)';
    }
}
