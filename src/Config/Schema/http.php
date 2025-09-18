<?php

declare(strict_types=1);

return [
    'rate_limit' => ['required' => false, 'type' => 'integer', 'min' => 1, 'default' => 60],
    'trusted_proxies' => ['required' => false, 'type' => 'array', 'default' => []],
];
