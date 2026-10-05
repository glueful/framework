<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Security;

use Glueful\Security\SecureSerializer;
use PHPUnit\Framework\TestCase;

/**
 * What the serializer writes it can read back: the size limit unserialize() enforces is enforced by
 * serialize() too, for JSON as for PHP. A cache entry written over the limit was refused on every
 * read after it (an image variant over 1MB: a 500 on each request after the first).
 */
final class SecureSerializerSizeTest extends TestCase
{
    public function testJsonOverTheLimitIsRefusedOnWritingNotOnReading(): void
    {
        $serializer = SecureSerializer::forCache();
        $this->expectException(\RuntimeException::class);
        $serializer->serialize(['data' => str_repeat('a', SecureSerializer::MAX_SIZE), 'mime' => 'image/png']);
    }

    public function testWhatItWritesUnderTheLimitReadsBack(): void
    {
        $serializer = SecureSerializer::forCache();
        $value = ['data' => str_repeat('a', SecureSerializer::MAX_SIZE - 64), 'mime' => 'image/png'];
        self::assertSame($value, $serializer->unserialize($serializer->serialize($value)));
    }
}
