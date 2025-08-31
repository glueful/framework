<?php

declare(strict_types=1);

namespace Glueful\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
class OpenApi
{
    /**
     * @param list<string> $tags
     * @param list<array<string, mixed>> $parameters
     * @param array<string, array<string, mixed>> $responses
     */
    public function __construct(
        public readonly string $path,
        public readonly string $method = 'GET',
        public readonly string $summary = '',
        public readonly string $description = '',
        public readonly array $tags = [],
        public readonly array $parameters = [],
        public readonly array $responses = []
    ) {
    }
}
