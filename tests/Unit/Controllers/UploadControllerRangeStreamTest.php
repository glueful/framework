<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Controllers;

use Glueful\Controllers\UploadController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * UploadController::advanceStream() positions a stream at the start of a Range.
 * Remote disks return streams that cannot seek, which a pipe stands in for here.
 */
final class UploadControllerRangeStreamTest extends TestCase
{
    private const BYTES = 'abcdefghij';

    public function testSeekableStreamMovesToOffset(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, self::BYTES);
        rewind($stream);

        $this->assertTrue($this->advance($stream, 4));
        $this->assertSame('efghij', stream_get_contents($stream));
        fclose($stream);
    }

    public function testNonSeekableStreamReadsForwardToOffset(): void
    {
        $stream = $this->pipe();
        $this->assertFalse(stream_get_meta_data($stream)['seekable']);

        $this->assertTrue($this->advance($stream, 4));
        $this->assertSame('efghij', stream_get_contents($stream));
        pclose($stream);
    }

    public function testNonSeekableStreamEndingBeforeOffsetFails(): void
    {
        $stream = $this->pipe();

        $this->assertFalse($this->advance($stream, 11));
        pclose($stream);
    }

    /** @return resource */
    private function pipe()
    {
        return popen('printf ' . self::BYTES, 'r');
    }

    /** @param resource $stream */
    private function advance($stream, int $offset): bool
    {
        $class = new ReflectionClass(UploadController::class);

        return $class->getMethod('advanceStream')->invoke(
            $class->newInstanceWithoutConstructor(),
            $stream,
            $offset,
        );
    }
}
