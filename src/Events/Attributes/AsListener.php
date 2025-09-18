<?php

declare(strict_types=1);

namespace Glueful\Events\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class AsListener
{
    public function __construct(
        public string $event,
        public int $priority = 0,
        public ?string $method = null, // when placed on class, which method to call
    ) {
    }
}
