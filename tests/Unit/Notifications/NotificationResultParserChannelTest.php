<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Notifications;

use Glueful\Notifications\Utils\NotificationResultParser;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 regression: parsing must use the *actual* channel. The SendNotification job previously
 * called `parseEmailResult()` unconditionally, so a failed SMS/whatsapp send was parsed against
 * `channels['email']` (absent) and lost its channel-specific error. Parsing with the real channel
 * surfaces the correct error code.
 */
final class NotificationResultParserChannelTest extends TestCase
{
    public function testParsingWithTheActualChannelSurfacesChannelSpecificError(): void
    {
        $result = [
            'status' => 'failed',
            'channels' => [
                'sms' => ['status' => 'failed', 'reason' => 'channel_unavailable'],
            ],
        ];

        $parsed = NotificationResultParser::parse($result, [], 'ok', 'fail', 'sms');

        self::assertFalse($parsed['success']);
        self::assertSame('sms_service_unavailable', $parsed['error_code']);
    }

    public function testParsingWithTheWrongChannelMissesIt(): void
    {
        // Demonstrates the old bug: labelling an SMS result as 'email' can't find the sms channel,
        // so it degrades to the generic failure instead of the channel-specific error.
        $result = [
            'status' => 'failed',
            'channels' => [
                'sms' => ['status' => 'failed', 'reason' => 'channel_unavailable'],
            ],
        ];

        $parsed = NotificationResultParser::parse($result, [], 'ok', 'fail', 'email');

        self::assertSame('notification_send_failed', $parsed['error_code']);
    }
}
