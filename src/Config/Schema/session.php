<?php

declare(strict_types=1);

return [
    'driver' => ['required' => true, 'type' => 'string'],
    'lifetime' => ['required' => false, 'type' => 'integer', 'min' => 1, 'default' => 120],
];
