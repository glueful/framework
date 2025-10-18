<?php

declare(strict_types=1);

use Glueful\Async\FiberScheduler;
use Glueful\Async\IO\AsyncStream;
use Glueful\Async\Contracts\Timeout;
use PHPUnit\Framework\TestCase;

final class AsyncStreamHelpersTest extends TestCase
{
    public function testReadLineAndWriteLine(): void
    {
        $scheduler = new FiberScheduler();
        $stream = fopen('php://temp', 'r+');
        $async = new AsyncStream($stream);

        $task = $scheduler->spawn(function () use ($async) {
            $async->writeLine('hello');
            // rewind to read
            rewind($async->getResource());
            $line = $async->readLine();
            return $line;
        });

        [$line] = $scheduler->all([$task]);
        $this->assertSame("hello\n", $line);
    }

    public function testReadExactly(): void
    {
        $scheduler = new FiberScheduler();
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'abcdef');
        rewind($stream);
        $async = new AsyncStream($stream);

        $task = $scheduler->spawn(function () use ($async) {
            return $async->readExactly(4, new Timeout(1.0));
        });

        [$data] = $scheduler->all([$task]);
        $this->assertSame('abcd', $data);
    }
}

