<?php

declare(strict_types=1);

return [
    'connection' => ['required' => true, 'type' => 'string'],
    'max_attempts' => ['required' => false, 'type' => 'integer', 'min' => 1, 'default' => 3],
    'retry_on_failure' => ['required' => false, 'type' => 'boolean', 'default' => true],
    'strategy' => [
        'required' => false,
        'type' => 'string',
        'enum' => ['immediate', 'delayed'],
        'default' => 'immediate'
    ],
];
