<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Security;

use Glueful\Security\SecureSerializer;
use PHPUnit\Framework\TestCase;

class PlainDataFixture
{
    public int $value = 1;
}

class GadgetFixture
{
    public function __destruct()
    {
        // A magic method that runs during GC/unserialize -- the classic POP-gadget trigger.
    }
}

/**
 * The namespace-based auto-allow for unserialize must not extend to classes that declare a
 * deserialization-trigger magic method (potential POP gadgets) -- only plain data classes.
 */
final class SecureSerializerGadgetGateTest extends TestCase
{
    private function hasGadget(string $className): bool
    {
        $serializer = new SecureSerializer();
        $method = new \ReflectionMethod($serializer, 'hasUnserializeGadget');
        $method->setAccessible(true);
        /** @var bool $result */
        $result = $method->invoke($serializer, $className);
        return $result;
    }

    public function test_plain_data_class_is_not_a_gadget(): void
    {
        self::assertFalse($this->hasGadget(PlainDataFixture::class));
    }

    public function test_class_with_destruct_is_a_gadget(): void
    {
        self::assertTrue($this->hasGadget(GadgetFixture::class));
    }

    public function test_unknown_class_is_not_a_gadget(): void
    {
        self::assertFalse($this->hasGadget('Glueful\\Models\\DoesNotExist'));
    }
}
