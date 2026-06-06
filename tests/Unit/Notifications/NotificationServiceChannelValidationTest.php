<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Notifications;

use Glueful\Notifications\Services\NotificationDispatcher;
use Glueful\Notifications\Services\NotificationService;
use Glueful\Repository\NotificationRepository;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1: `default_channels` is normalized structurally (trim, non-empty, dedupe — no
 * lowercase) at construction; the old hardcoded valid-channel membership check is gone.
 * Semantic validation now happens at dispatch via the dispatcher's channel_not_found /
 * channel_unavailable.
 */
final class NotificationServiceChannelValidationTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function service(array $config): NotificationService
    {
        $dispatcher = $this->createMock(NotificationDispatcher::class);
        $repository = $this->createMock(NotificationRepository::class);

        return new NotificationService($dispatcher, $repository, null, null, $config);
    }

    /** @return array<string> */
    private function defaultChannels(NotificationService $service): array
    {
        $method = new \ReflectionMethod($service, 'getDefaultChannels');
        $method->setAccessible(true);

        return $method->invoke($service);
    }

    public function testCustomChannelNameIsAllowedAndCasePreserved(): void
    {
        // 'MyChannel' was rejected by the old hardcoded list; it must be allowed now,
        // and its casing preserved (no lowercasing in a post-1.0 framework).
        $service = $this->service(['default_channels' => ['MyChannel', 'database']]);
        self::assertSame(['MyChannel', 'database'], $this->defaultChannels($service));
    }

    public function testExactDuplicatesRemovedButCasePreserved(): void
    {
        $service = $this->service(['default_channels' => ['database', 'Database', 'database']]);
        self::assertSame(['database', 'Database'], $this->defaultChannels($service));
    }

    public function testWhitespaceIsTrimmedAndBlankEntriesDropped(): void
    {
        $service = $this->service(['default_channels' => ['  database  ', '   ']]);
        self::assertSame(['database'], $this->defaultChannels($service));
    }

    public function testEmptyDefaultChannelsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service(['default_channels' => []]);
    }

    public function testNonStringChannelThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore-next-line intentional bad input */
        $this->service(['default_channels' => [123]]);
    }
}
