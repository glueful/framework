<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Performance;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationChannel;
use Glueful\Notifications\Services\ChannelManager;
use Glueful\Performance\MemoryAlertingService;
use Glueful\Performance\MemoryManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * The `email` memory-alert channel must deliver through a registered `email` notification
 * channel (e.g. glueful/email-notification) and, when none is registered, say so once — not
 * log a fake "Would send email alert" line.
 */
final class MemoryAlertingEmailChannelTest extends TestCase
{
    /** @var list<array{level: mixed, message: string}> */
    private array $logs = [];

    private function logger(): AbstractLogger
    {
        return new class ($this->logs) extends AbstractLogger {
            /** @param list<array{level: mixed, message: string}> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = ['level' => $level, 'message' => (string) $message];
            }
        };
    }

    private function service(?ChannelManager $channels): MemoryAlertingService
    {
        $context = ApplicationContext::forTesting(dirname(__DIR__, 3));
        $context->overrideConfig('app.performance.memory.alerting', [
            'enabled' => true,
            'cooldown' => 0,
            'channels' => ['email'],
            'notify_threshold' => 0.5,
            'critical_threshold' => 0.99,
            'alert_rate_limit' => 100,
        ]);
        $context->overrideConfig('notifications.email', ['to' => 'ops@example.test']);
        $context->setContainer(new Container(
            $channels !== null ? [ChannelManager::class => fn() => $channels] : []
        ));

        $memory = $this->createMock(MemoryManager::class);
        $memory->method('getCurrentUsage')->willReturn([
            'current' => 600,
            'peak' => 600,
            'limit' => 1000,
            'percentage' => 0.6,
            'peak_percentage' => 0.6,
        ]);

        return new MemoryAlertingService($memory, $this->logger(), $context);
    }

    public function testSendsThroughTheRegisteredEmailChannel(): void
    {
        $channel = new RecordingEmailChannel();
        $manager = new ChannelManager();
        $manager->registerChannel($channel);

        $this->service($manager)->checkAndAlert('worker');

        $this->assertCount(1, $channel->sent);
        [$notifiable, $data] = $channel->sent[0];
        $this->assertSame('ops@example.test', $notifiable->routeNotificationFor('email'));
        $this->assertStringContainsString('High memory usage detected in worker', (string) $data['subject']);
        $this->assertStringContainsString('High memory usage detected in worker', (string) $data['text_content']);
        foreach ($this->logs as $log) {
            $this->assertStringNotContainsString('Would send email alert', $log['message']);
        }
    }

    public function testWarnsOnceWhenNoEmailChannelIsRegistered(): void
    {
        $service = $this->service(new ChannelManager());

        $service->checkAndAlert('worker');
        $service->checkAndAlert('worker');

        $warnings = array_values(array_filter(
            $this->logs,
            static fn(array $l): bool => str_contains($l['message'], 'glueful/email-notification')
        ));
        $this->assertCount(1, $warnings, 'The missing-channel warning must be logged once, not per alert.');
        $this->assertSame('warning', $warnings[0]['level']);
        foreach ($this->logs as $log) {
            $this->assertStringNotContainsString('Would send email alert', $log['message']);
        }
    }
}

final class RecordingEmailChannel implements NotificationChannel
{
    /** @var list<array{0: Notifiable, 1: array<string, mixed>}> */
    public array $sent = [];

    public function getChannelName(): string
    {
        return 'email';
    }

    public function send(Notifiable $notifiable, array $data): bool
    {
        $this->sent[] = [$notifiable, $data];
        return true;
    }

    public function format(array $data, Notifiable $notifiable): array
    {
        return $data;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getConfig(): array
    {
        return [];
    }
}
