<?php

declare(strict_types=1);

namespace Glueful\Tests\Fixtures;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Minimal PSR-11 container for testing.
 */
class TestContainer implements ContainerInterface
{
    /** @var array<string, mixed> */
    private array $services = [];

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }

    public function get(string $id): mixed
    {
        if ($this->has($id)) {
            return $this->services[$id];
        }
        throw new class ("Service '$id' not found") extends RuntimeException implements NotFoundExceptionInterface {
        };
    }

    public function set(string $id, mixed $service): void
    {
        $this->services[$id] = $service;
    }
}
