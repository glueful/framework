<?php

declare(strict_types=1);

namespace Glueful\Bootstrap;

/**
 * Manages request lifecycle for long-running servers.
 */
final class RequestLifecycle
{
    /** @var array<callable> */
    private array $onBegin = [];

    /** @var array<callable> */
    private array $onEnd = [];

    public function __construct(
        private readonly ApplicationContext $context,
    ) {
    }

    public function onBeginRequest(callable $callback): void
    {
        $this->onBegin[] = $callback;
    }

    public function onEndRequest(callable $callback): void
    {
        $this->onEnd[] = $callback;
    }

    public function beginRequest(): void
    {
        foreach ($this->onBegin as $callback) {
            $callback($this->context);
        }
    }

    public function endRequest(): void
    {
        foreach ($this->onEnd as $callback) {
            $callback($this->context);
        }

        $this->context->resetRequestState();
    }

    public function getContext(): ApplicationContext
    {
        return $this->context;
    }
}
