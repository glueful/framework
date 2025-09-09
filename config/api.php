<?php

return [
    'field_selection' => [
        // Global defaults
        'enabled'    => true,
        'strict'     => false,
        'maxDepth'   => 6,
        'maxFields'  => 200,
        'maxItems'   => 1000,

        // Optional named whitelists (referenced by whitelistKey)
        'whitelists' => [
            // 'user' => ['id','name','email','posts','comments','profile'],
            // 'post' => ['id','title','body','comments','author'],
        ],
    ],
];
