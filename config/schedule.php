<?php

/**
 * Scheduler Configuration
 *
 * Defines scheduled jobs and cron tasks for the application.
 * Enhanced with better error handling and monitoring.
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
            'queue' => 'maintenance',
            'timeout' => 300,
            'retry_attempts' => 3,
        ],
        [
            'name' => 'log_cleanup',
            'schedule' => '0 1 * * *',
            'handler_class' => 'Glueful\\Queue\\Jobs\\LogCleanupJob',
            'parameters' => [
                'retentionDays' => env('LOG_RETENTION_DAYS', 30)
            ],
            'description' => 'Clean up old log files',
            'enabled' => env('LOG_CLEANUP_ENABLED', true),
            'queue' => 'maintenance',
            'timeout' => 600,
            'retry_attempts' => 2,
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
            'queue' => 'critical',
            'timeout' => 1800,
            'retry_attempts' => 1,
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
            'queue' => 'maintenance',
            'timeout' => 600,
            'retry_attempts' => 2,
        ],
        [
            'name' => 'notification_retry_processor',
            'schedule' => '*/10 * * * *',
            'handler_class' => 'Glueful\\Queue\\Jobs\\NotificationRetryJob',
            'parameters' => ['limit' => 50],
            'description' => 'Process queued notification retries',
            'enabled' => env('NOTIFICATION_RETRIES_ENABLED', true),
            'queue' => 'notifications',
            'timeout' => 300,
            'retry_attempts' => 2,
        ],
    ],

    'settings' => [
        'enabled' => env('SCHEDULER_ENABLED', true),
        'max_concurrent_jobs' => env('MAX_CONCURRENT_JOBS', 5),
        'default_timeout' => env('DEFAULT_JOB_TIMEOUT', 300),
        'default_queue' => env('DEFAULT_SCHEDULED_QUEUE', 'scheduled'),
        'log_execution' => env('LOG_JOB_EXECUTION', true),
        'notification_on_failure' => env('NOTIFY_ON_JOB_FAILURE', env('APP_ENV') === 'production'),
        'queue_connection' => env('SCHEDULE_QUEUE_CONNECTION', 'default'),
        'use_queue_for_all_jobs' => env('USE_QUEUE_FOR_SCHEDULED_JOBS', true),
    ],

    'queue_mapping' => [
        'critical' => ['database_backup'],
        'maintenance' => ['session_cleaner', 'log_cleanup', 'cache_maintenance'],
        'notifications' => ['notification_retry_processor'],
        'system' => ['queue_maintenance'],
    ],
];
