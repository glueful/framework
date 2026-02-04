<?php

declare(strict_types=1);

namespace Glueful\Tests\Fixtures;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Test controller for route cache tests.
 * Must be a real class (not closure) since closures cannot be cached.
 */
class RouteCacheTestController
{
    public function show(int $id): JsonResponse
    {
        return new JsonResponse(['id' => $id]);
    }

    public function showPost(string $slug): JsonResponse
    {
        return new JsonResponse(['slug' => $slug]);
    }

    public function resource(string $version, int $id): JsonResponse
    {
        return new JsonResponse(['version' => $version, 'id' => $id]);
    }

    public function localized(string $locale, int $id): JsonResponse
    {
        return new JsonResponse(['locale' => $locale, 'id' => $id]);
    }

    public function index(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
