<?php

/**
 * Scheduler Configuration
 *
 * Each job runs inline, in the process that runs `php glueful queue:scheduler run`, when its cron
 * `schedule` is due. A job takes `name`, `schedule`, `handler_class`, optional `parameters`
 * (handed to the handler), optional `enabled`, optional `persistence` (register it in the
 * database instead of memory) and a `description`. Nothing else is read: there is no per-job
 * queue, timeout or retry count on this path.
 */

return [
    'jobs' => [
        [
            'name' => 'session_cleaner',
            'schedule' => '0 0 * * *',
            'handler_class' => 'Glueful\\Queue\\Jobs\\SessionCleanupJob',
            'parameters' => ['cleanupType' => 'expired'],
            'description' => 'Clean up expired user sessions',
            'enabled' => env('SESSION_CLEANER_ENABLED', true),
        ],
        [
            'name' => 'log_cleanup',
            'schedule' => '0 1 * * *',
            'handler_class' => 'Glueful\\Queue\\Jobs\\LogCleanupJob',
            'parameters' => [
                'options' => ['retention_days' => env('LOG_RETENTION_DAYS', 30)],
            ],
            'description' => 'Clean up old log files',
            'enabled' => env('LOG_CLEANUP_ENABLED', true),
        ],
        [
            'name' => 'database_backup',
            'schedule' => env('DB_BACKUP_SCHEDULE', '0 2 * * *'),
            'handler_class' => 'Glueful\\Queue\\Jobs\\DatabaseBackupJob',
            'parameters' => [
                'backupType' => 'full',
                'options' => [
                    'retention_days' => env('BACKUP_RETENTION_DAYS', 7)
                ]
            ],
            'enabled' => env('DB_BACKUP_ENABLED', env('APP_ENV') === 'production'),
            'description' => 'Create automated database backups',
        ],
        [
            'name' => 'cache_maintenance',
            'schedule' => '0 3 * * *',
            'handler_class' => 'Glueful\\Queue\\Jobs\\CacheMaintenanceJob',
            'parameters' => [
                'operation' => 'fullCleanup'
            ],
            'description' => 'Perform cache maintenance',
            'enabled' => env('CACHE_MAINTENANCE_ENABLED', true),
        ],
        [
            'name' => 'notification_retry_processor',
            'schedule' => '*/10 * * * *',
            'handler_class' => 'Glueful\\Queue\\Jobs\\NotificationRetryJob',
            'parameters' => ['options' => ['limit' => 50]],
            'description' => 'Process queued notification retries',
            'enabled' => env('NOTIFICATION_RETRIES_ENABLED', true),
        ],
        [
            'name' => 'webhook_cleanup',
            'schedule' => '30 3 * * *',
            'handler_class' => 'Glueful\\Api\\Webhooks\\Jobs\\WebhookCleanupJob',
            'description' => 'Delete webhook delivery records past api.webhooks.cleanup retention',
            'enabled' => env('WEBHOOK_CLEANUP_ENABLED', true),
        ],
    ],

];
