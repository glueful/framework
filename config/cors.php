<?php

$allowedOriginsRaw = env(
    'CORS_ALLOWED_ORIGINS',
    env('APP_ENV') === 'development' ? '*' : ''
);
$allowedOriginsRaw = is_string($allowedOriginsRaw) ? trim($allowedOriginsRaw) : '';

$allowedOriginPatternsRaw = env('CORS_ALLOWED_ORIGIN_PATTERNS', '');
$allowedOriginPatternsRaw = is_string($allowedOriginPatternsRaw) ? $allowedOriginPatternsRaw : '';

$allowedHeadersRaw = env('CORS_ALLOWED_HEADERS', '*');
$allowedHeadersRaw = is_string($allowedHeadersRaw) ? trim($allowedHeadersRaw) : '*';

$exposeHeadersRaw = env('CORS_EXPOSE_HEADERS', '');
$exposeHeadersRaw = is_string($exposeHeadersRaw) ? $exposeHeadersRaw : '';

$allowedOrigins = $allowedOriginsRaw === '*'
    ? []
    : array_values(array_filter(array_map('trim', explode(',', $allowedOriginsRaw))));

$allowedOriginPatterns = array_values(array_filter(array_map('trim', explode(',', $allowedOriginPatternsRaw))));

$allowHeaders = $allowedHeadersRaw === '*'
    ? '*'
    : array_values(array_filter(array_map('trim', explode(',', $allowedHeadersRaw))));

$exposeHeaders = array_values(array_filter(array_map('trim', explode(',', $exposeHeadersRaw))));

return [
    // Origins
    'allow_all_origins' => $allowedOriginsRaw === '*',
    'allowed_origins' => $allowedOrigins,
    'allowed_origin_patterns' => $allowedOriginPatterns,

    // Headers
    'allow_headers' => $allowHeaders,
    'expose_headers' => $exposeHeaders,

    // Credentials & caching
    'allow_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),
    'max_age' => (int) env('CORS_MAX_AGE', 86400),

    // Environment overrides
    'development_allow_all' => env('APP_ENV') === 'development',
];
