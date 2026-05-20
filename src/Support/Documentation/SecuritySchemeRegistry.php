<?php

declare(strict_types=1);

namespace Glueful\Support\Documentation;

/**
 * Registry of OpenAPI security schemes derived from config.
 *
 * Falls back to a single BearerAuth scheme when config is empty so the
 * spec is never published without at least one declared scheme.
 */
final class SecuritySchemeRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $schemes;

    /**
     * @param array<string, array<string, mixed>> $schemes
     */
    public function __construct(array $schemes)
    {
        $this->schemes = $schemes === [] ? $this->defaultSchemes() : $schemes;
    }

    /** @return array<string, array<string, mixed>> */
    public function getSchemes(): array
    {
        return $this->schemes;
    }

    public function has(string $name): bool
    {
        return isset($this->schemes[$name]);
    }

    /** @return array<string, array<string, mixed>> */
    private function defaultSchemes(): array
    {
        return [
            'BearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT',
                'description' => 'JWT Authorization header using the Bearer scheme',
            ],
        ];
    }
}
