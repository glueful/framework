<?php

return [
    'tracing' => [
        'enabled' => env('TRACING_ENABLED', false),
        'driver' => env('TRACING_DRIVER', 'noop'), // 'otel', 'datadog', 'newrelic', 'noop'
        'service_name' => env('TRACING_SERVICE_NAME', 'glueful-app'),
        'service_version' => env('TRACING_SERVICE_VERSION', '1.0.0'),

        // Driver-specific configs
        'drivers' => [
          'otel' => [ /* exporter, endpoint, headers */ ],
          'datadog' => [ /* service, env, version */ ],
          'newrelic' => [ /* app name, license */ ],
        ],
    ]
];
