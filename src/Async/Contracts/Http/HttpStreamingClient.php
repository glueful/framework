<?php

declare(strict_types=1);

namespace Glueful\Async\Contracts\Http;

use Glueful\Async\Contracts\Task;
use Glueful\Async\Contracts\Timeout;
use Glueful\Async\Contracts\CancellationToken;
use Psr\Http\Message\RequestInterface;

/**
 * Optional extension interface for streaming HTTP responses.
 *
 * Implementations should invoke the provided $onChunk callback as body data
 * arrives during the transfer, and still return a Task that resolves to the
 * final PSR-7 Response (including full body) for convenience.
 */
interface HttpStreamingClient
{
    /**
     * Sends an HTTP request asynchronously and invokes $onChunk for each body chunk.
     *
     * @param RequestInterface $request
     * @param callable(string):void $onChunk Callback receiving raw body chunks
     * @param Timeout|null $timeout Optional timeout
     * @param CancellationToken|null $token Optional cancellation token
     * @return Task A task resolving to the final PSR-7 Response
     */
    public function sendAsyncStream(
        RequestInterface $request,
        callable $onChunk,
        ?Timeout $timeout = null,
        ?CancellationToken $token = null
    ): Task;
}
