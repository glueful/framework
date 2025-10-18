<?php

declare(strict_types=1);

return [
    'scheduler' => [
        'poll_interval_seconds' => (float) (env('ASYNC_POLL_INTERVAL_SECONDS', 0.01)),
        'max_concurrent_tasks' => (int) (env('ASYNC_MAX_CONCURRENT_TASKS', 0)),
        'max_task_execution_seconds' => (float) (env('ASYNC_MAX_TASK_EXECUTION_SECONDS', 0.0)),
    ],
    'http' => [
        'poll_interval_seconds' => (float) (env('ASYNC_HTTP_POLL_INTERVAL_SECONDS', 0.01)),
        'max_retries' => (int) (env('ASYNC_HTTP_MAX_RETRIES', 0)),
        'retry_delay_seconds' => (float) (env('ASYNC_HTTP_RETRY_DELAY_SECONDS', 0.0)),
        'retry_on_status' => [429, 500, 502, 503, 504],
        'max_concurrent' => (int) (env('ASYNC_HTTP_MAX_CONCURRENT', 0)),
    ],
    'streams' => [
        'buffer_size' => (int) (env('ASYNC_STREAM_BUFFER_SIZE', 8192)),
        'read_timeout_seconds' => (float) (env('ASYNC_STREAM_READ_TIMEOUT', 60)),
        'write_timeout_seconds' => (float) (env('ASYNC_STREAM_WRITE_TIMEOUT', 60)),
    ],
    'limits' => [
        'max_memory_mb' => (int) (env('ASYNC_MAX_MEMORY_MB', 512)),
        'max_open_file_descriptors' => (int) (env('ASYNC_MAX_FILE_DESCRIPTORS', 1024)),
    ],
];
