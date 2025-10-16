<?php

declare(strict_types=1);

namespace Glueful\Async\Contracts\Http;

use Glueful\Async\Contracts\Task;
use Glueful\Async\Contracts\Timeout;
use Psr\Http\Message\RequestInterface;

interface HttpClient
{
    public function sendAsync(RequestInterface $request, ?Timeout $timeout = null): Task;
    /**
     * @param array<int, RequestInterface> $requests
     * @return array<int, Task>
     */
    public function poolAsync(array $requests, ?Timeout $timeout = null): array;
}
