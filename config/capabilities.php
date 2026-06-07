<?php

/**
 * Core Capability Schema Switchboard
 *
 * Controls which core platform-capability migrations the framework registers (see the
 * MigrationManager factory in CoreProvider). Each flag gates whether that capability's DB schema
 * is installed, under the source `glueful/framework:<capability>`.
 *
 * Only capabilities WITHOUT a natural driver/enable signal live here. Capabilities whose
 * DB-backing is already implied by their own config are NOT listed (the factory derives them to
 * avoid a second source of truth):
 *   - locks   → lock.default === 'database'
 *   - queue   → queue.default === 'database'
 *   - uploads → uploads.enabled
 *
 * Auth schema (auth_sessions/auth_refresh_tokens/api_keys) is always installed and not gated here.
 */

return [
    // Scheduler persists jobs/executions (scheduled_jobs, job_executions).
    'scheduler' => env('SCHEDULE_DATABASE_STORE', true),

    // Notifications persistence (notifications, deliveries, preferences, templates, retry queue).
    'notifications' => env('NOTIFICATIONS_DATABASE_STORE', true),

    // API metrics (api_metrics, api_metrics_daily, api_rate_limits).
    'metrics' => env('METRICS_DATABASE_STORE', true),
];
