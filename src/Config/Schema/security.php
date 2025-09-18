<?php

declare(strict_types=1);

return [
    'jwt' => [
        'required' => false,
        'type' => 'array',
        'default' => [],
        'schema' => [
            'secret' => ['required' => true, 'type' => 'string'],
            'ttl' => ['required' => false, 'type' => 'integer', 'min' => 60, 'default' => 3600],
        ],
    ],
];
