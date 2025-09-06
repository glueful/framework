<?php

// Auto-generated route cache - DO NOT EDIT
// Generated: 2025-09-05 19:46:56

return [
    'static' => [
        'GET:/' => [
            'handler' => array (
  'type' => 'closure',
  'target' => 
  array (
    'warning' => 'Closure handlers cannot be cached reliably',
    'reflection' => 
    array (
      'filename' => '/Users/michaeltawiahsowah/Sites/glueful/framework/tests/Integration/RouterIntegrationTest.php',
      'line_start' => 139,
      'line_end' => 139,
      'parameters' => 
      array (
      ),
    ),
  ),
  'metadata' => 
  array (
    'created_at' => '2025-09-05T19:46:56+00:00',
    'php_version' => '8.2.28',
    'filename' => '/Users/michaeltawiahsowah/Sites/glueful/framework/tests/Integration/RouterIntegrationTest.php',
    'line' => 139,
  ),
),
            'middleware' => array (
)
        ],
        'GET:/about' => [
            'handler' => array (
  'type' => 'closure',
  'target' => 
  array (
    'warning' => 'Closure handlers cannot be cached reliably',
    'reflection' => 
    array (
      'filename' => '/Users/michaeltawiahsowah/Sites/glueful/framework/tests/Integration/RouterIntegrationTest.php',
      'line_start' => 140,
      'line_end' => 140,
      'parameters' => 
      array (
      ),
    ),
  ),
  'metadata' => 
  array (
    'created_at' => '2025-09-05T19:46:56+00:00',
    'php_version' => '8.2.28',
    'filename' => '/Users/michaeltawiahsowah/Sites/glueful/framework/tests/Integration/RouterIntegrationTest.php',
    'line' => 140,
  ),
),
            'middleware' => array (
)
        ],
        'POST:/contact' => [
            'handler' => array (
  'type' => 'closure',
  'target' => 
  array (
    'warning' => 'Closure handlers cannot be cached reliably',
    'reflection' => 
    array (
      'filename' => '/Users/michaeltawiahsowah/Sites/glueful/framework/tests/Integration/RouterIntegrationTest.php',
      'line_start' => 141,
      'line_end' => 141,
      'parameters' => 
      array (
      ),
    ),
  ),
  'metadata' => 
  array (
    'created_at' => '2025-09-05T19:46:56+00:00',
    'php_version' => '8.2.28',
    'filename' => '/Users/michaeltawiahsowah/Sites/glueful/framework/tests/Integration/RouterIntegrationTest.php',
    'line' => 141,
  ),
),
            'middleware' => array (
)
        ],
        'GET:/api/users' => [
            'handler' => array (
  'type' => 'closure',
  'target' => 
  array (
    'warning' => 'Closure handlers cannot be cached reliably',
    'reflection' => 
    array (
      'filename' => '/Users/michaeltawiahsowah/Sites/glueful/framework/tests/Integration/RouterIntegrationTest.php',
      'line_start' => 151,
      'line_end' => 151,
      'parameters' => 
      array (
      ),
    ),
  ),
  'metadata' => 
  array (
    'created_at' => '2025-09-05T19:46:56+00:00',
    'php_version' => '8.2.28',
    'filename' => '/Users/michaeltawiahsowah/Sites/glueful/framework/tests/Integration/RouterIntegrationTest.php',
    'line' => 151,
  ),
),
            'middleware' => array (
  0 => 'api',
)
        ],
        'POST:/api/users' => [
            'handler' => array (
  'type' => 'closure',
  'target' => 
  array (
    'warning' => 'Closure handlers cannot be cached reliably',
    'reflection' => 
    array (
      'filename' => '/Users/michaeltawiahsowah/Sites/glueful/framework/tests/Integration/RouterIntegrationTest.php',
      'line_start' => 152,
      'line_end' => 152,
      'parameters' => 
      array (
      ),
    ),
  ),
  'metadata' => 
  array (
    'created_at' => '2025-09-05T19:46:56+00:00',
    'php_version' => '8.2.28',
    'filename' => '/Users/michaeltawiahsowah/Sites/glueful/framework/tests/Integration/RouterIntegrationTest.php',
    'line' => 152,
  ),
),
            'middleware' => array (
  0 => 'api',
)
        ],
    ],
    'dynamic' => [
        'GET' => [
            [
                'pattern' => '#^/users/(\d+)$#u',
                'handler' => array (
  'type' => 'closure',
  'target' => 
  array (
    'warning' => 'Closure handlers cannot be cached reliably',
    'reflection' => 
    array (
      'filename' => '/Users/michaeltawiahsowah/Sites/glueful/framework/tests/Integration/RouterIntegrationTest.php',
      'line_start' => 144,
      'line_end' => 144,
      'parameters' => 
      array (
        0 => 'id',
      ),
    ),
  ),
  'metadata' => 
  array (
    'created_at' => '2025-09-05T19:46:56+00:00',
    'php_version' => '8.2.28',
    'filename' => '/Users/michaeltawiahsowah/Sites/glueful/framework/tests/Integration/RouterIntegrationTest.php',
    'line' => 144,
  ),
),
                'params' => array (
  0 => 'id',
)
            ],
            [
                'pattern' => '#^/posts/([a-z0-9-]+)$#u',
                'handler' => array (
  'type' => 'closure',
  'target' => 
  array (
    'warning' => 'Closure handlers cannot be cached reliably',
    'reflection' => 
    array (
      'filename' => '/Users/michaeltawiahsowah/Sites/glueful/framework/tests/Integration/RouterIntegrationTest.php',
      'line_start' => 146,
      'line_end' => 146,
      'parameters' => 
      array (
        0 => 'slug',
      ),
    ),
  ),
  'metadata' => 
  array (
    'created_at' => '2025-09-05T19:46:56+00:00',
    'php_version' => '8.2.28',
    'filename' => '/Users/michaeltawiahsowah/Sites/glueful/framework/tests/Integration/RouterIntegrationTest.php',
    'line' => 146,
  ),
),
                'params' => array (
  0 => 'slug',
)
            ],
        ],
    ]
];
