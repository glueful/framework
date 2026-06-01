<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue;

use Glueful\Queue\Jobs\SendNotification;
use PHPUnit\Framework\TestCase;

/**
 * Ensures the framework notification job accepts the 'whatsapp' type so the
 * Conversa extension's whatsapp channel can be delivered asynchronously.
 */
final class SendNotificationTypesTest extends TestCase
{
    public function testWhatsappIsASupportedType(): void
    {
        $ref = new \ReflectionClass(SendNotification::class);
        $supported = $ref->getConstant('SUPPORTED_TYPES');

        $this->assertIsArray($supported);
        $this->assertContains('whatsapp', $supported);
        $this->assertContains('sms', $supported); // existing types unchanged
        $this->assertContains('email', $supported);
    }
}
