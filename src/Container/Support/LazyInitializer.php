<?php

declare(strict_types=1);

namespace Glueful\Container\Support;

use Psr\Container\ContainerInterface;

final class LazyInitializer
{
    /** @var array<int,string> */
    private array $backgroundIds;
    /** @var array<int,string> */
    private array $requestTimeIds;

    /**
     * @param array<int,string> $backgroundIds
     * @param array<int,string> $requestTimeIds
     */
    public function __construct(
        private ContainerInterface $container,
        array $backgroundIds = [],
        array $requestTimeIds = []
    ) {
        $this->backgroundIds = array_values(array_unique(array_map('strval', $backgroundIds)));
        $this->requestTimeIds = array_values(array_unique(array_map('strval', $requestTimeIds)));
    }

    /**
     * Warm a list of service IDs (instantiates once for shared services).
     * @param array<int,string> $ids
     */
    public function warm(array $ids): void
    {
        foreach ($ids as $id) {
            try {
                if ($this->container->has($id)) {
                    $this->container->get($id);
                }
            } catch (\Throwable) {
                // Warmup is best-effort; ignore individual failures
            }
        }
    }

    public function initializeBackground(): void
    {
        $this->warm($this->backgroundIds);
    }

    public function activateRequestTime(): void
    {
        $this->warm($this->requestTimeIds);
    }

    /**
     * @return array<string,mixed>
     */
    public function stats(): array
    {
        return [
            'background' => $this->backgroundIds,
            'request_time' => $this->requestTimeIds,
        ];
    }
}
