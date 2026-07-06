<?php

declare(strict_types=1);

namespace Glueful\Routing;

/**
 * Registry of SPA/frontend mounts declared via ServiceProvider::serveFrontend().
 *
 * serveFrontend() populates this on every boot — provider boot() runs on every
 * request, even when the route table was reconstructed from the compiled route
 * cache — so the mount metadata is always available at dispatch time. The SPA
 * controller resolves which mount a request belongs to by longest-prefix match
 * on the request path (never from a route parameter). That keeps a single,
 * mount-agnostic controller serializable into the route cache, which closures
 * are not.
 */
final class FrontendMountRegistry
{
    /**
     * Mounts keyed by normalized prefix (leading slash, no trailing slash).
     *
     * @var array<string, array{dir: string, spaFallback: bool, name: string}>
     */
    private array $mounts = [];

    /**
     * Record a mount. $dir must be an already-resolved realpath (serveFrontend
     * resolves and validates it before registering), so the controller can
     * safely use it as the containment boundary for path-traversal checks.
     */
    public function register(string $path, string $dir, bool $spaFallback, string $name): void
    {
        $this->mounts[$this->normalize($path)] = [
            'dir' => $dir,
            'spaFallback' => $spaFallback,
            'name' => $name,
        ];
    }

    /**
     * Resolve the mount that owns a request path by longest matching prefix.
     * Returns null when no mount matches.
     *
     * @return array{prefix: string, dir: string, spaFallback: bool, name: string}|null
     */
    public function match(string $requestPath): ?array
    {
        $requestPath = '/' . ltrim($requestPath, '/');
        $best = null;
        foreach ($this->mounts as $prefix => $mount) {
            // The mount root itself ('/admin') or anything beneath it ('/admin/...').
            if ($requestPath === $prefix || str_starts_with($requestPath, $prefix . '/')) {
                if ($best === null || strlen($prefix) > strlen((string) $best['prefix'])) {
                    $best = ['prefix' => $prefix] + $mount;
                }
            }
        }
        return $best;
    }

    /**
     * All registered mounts keyed by prefix (introspection/testing).
     *
     * @return array<string, array{dir: string, spaFallback: bool, name: string}>
     */
    public function all(): array
    {
        return $this->mounts;
    }

    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
