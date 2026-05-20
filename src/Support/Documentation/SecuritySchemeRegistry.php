<?php

declare(strict_types=1);

namespace Glueful\Support\Documentation;

/**
 * Registry of OpenAPI security schemes derived from config.
 *
 * Falls back to a single BearerAuth scheme when config is empty so the
 * spec is never published without at least one declared scheme. Routes
 * carry middleware names; the registry maps those names to declared
 * schemes so per-operation security requirements can be computed.
 */
final class SecuritySchemeRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $schemes;

    /** @var array<string, list<string>> */
    private array $middlewareMap;

    /**
     * @param array<string, array<string, mixed>> $schemes
     * @param array<string, list<string>>          $middlewareMap
     */
    public function __construct(array $schemes, array $middlewareMap = [])
    {
        $this->schemes = $schemes === [] ? $this->defaultSchemes() : $schemes;
        $this->middlewareMap = $middlewareMap;
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

    /**
     * Resolve OpenAPI security requirements for a list of route middleware.
     *
     * @param  list<string> $middleware
     * @return list<array<string, list<string>>>
     */
    public function securityFor(array $middleware): array
    {
        $requirements = [];
        $seen = [];
        foreach ($middleware as $name) {
            foreach ($this->middlewareMap[$name] ?? [] as $schemeName) {
                if (!isset($this->schemes[$schemeName]) || isset($seen[$schemeName])) {
                    continue;
                }
                $seen[$schemeName] = true;
                $requirements[] = [$schemeName => []];
            }
        }
        return $requirements;
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
