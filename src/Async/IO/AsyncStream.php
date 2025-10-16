<?php

declare(strict_types=1);

namespace Glueful\Async\IO;

use Glueful\Async\Contracts\CancellationToken;
use Glueful\Async\Contracts\Timeout;
use Glueful\Async\Internal\ReadOp;
use Glueful\Async\Internal\WriteOp;

final class AsyncStream
{
    /** @var resource */
    private $stream;

    /**
     * @param resource $stream Readable/writable stream
     */
    public function __construct($stream)
    {
        if (!\is_resource($stream)) {
            throw new \InvalidArgumentException('AsyncStream expects a stream resource');
        }
        $this->stream = $stream;
        @stream_set_blocking($this->stream, false);
    }

    /**
     * @return resource
     */
    public function getResource()
    {
        return $this->stream;
    }

    public function read(int $length, ?Timeout $timeout = null, ?CancellationToken $token = null): string
    {
        $deadline = $timeout !== null ? (microtime(true) + max(0.0, $timeout->seconds)) : null;
        $data = '';
        while (\strlen($data) < $length) {
            if ($token !== null && $token->isCancelled()) {
                $token->throwIfCancelled();
            }
            $chunk = @fread($this->stream, $length - \strlen($data));
            if (\is_string($chunk) && $chunk !== '') {
                $data .= $chunk;
                continue;
            }
            if (feof($this->stream)) {
                return $data; // EOF
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                throw new \RuntimeException('async read timeout');
            }
            \Fiber::suspend(new ReadOp($this->stream, $deadline, $token));
        }
        return $data;
    }

    public function write(string $buffer, ?Timeout $timeout = null, ?CancellationToken $token = null): int
    {
        $deadline = $timeout !== null ? (microtime(true) + max(0.0, $timeout->seconds)) : null;
        $written = 0;
        $len = \strlen($buffer);
        while ($written < $len) {
            if ($token !== null && $token->isCancelled()) {
                $token->throwIfCancelled();
            }
            $n = @fwrite($this->stream, substr($buffer, $written));
            if (\is_int($n) && $n > 0) {
                $written += $n;
                continue;
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                throw new \RuntimeException('async write timeout');
            }
            \Fiber::suspend(new WriteOp($this->stream, $deadline, $token));
        }
        return $written;
    }
}
