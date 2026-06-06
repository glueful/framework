<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Notifications;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationChannel;
use Glueful\Notifications\Models\Notification;
use Glueful\Notifications\Services\NotificationDispatcher;
use Glueful\Notifications\Services\NotificationService;
use Glueful\Queue\Jobs\DispatchNotificationChannels;
use Glueful\Testing\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Phase 5 / Task 5d: end-to-end proof that an extension that registers a channel from its
 * ServiceProvider::boot() (via the new helper) makes that channel dispatchable through the
 * container-wired dispatcher, and that a rewired consumer resolves the SAME shared service —
 * no local managers.
 */
final class ExtensionChannelRegistrationTest extends TestCase
{
    private string $appPath;

    protected function setUp(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-notif-reg-' . uniqid('', true);
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        file_put_contents($cfg . '/app.php', "<?php\nreturn ['name' => 'Test', 'env' => 'testing'];\n");
        file_put_contents(
            $cfg . '/database.php',
            "<?php\nreturn ['engine' => 'sqlite', 'sqlite' => ['primary' => '" . $this->appPath . "/t.sqlite'], "
            . "'pooling' => ['enabled' => false]];\n"
        );
        file_put_contents(
            $cfg . '/cache.php',
            "<?php\nreturn ['enabled' => false, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]];\n"
        );
        file_put_contents($cfg . '/session.php', "<?php\nreturn ['jwt_key' => 'test'];\n");
        file_put_contents($cfg . '/security.php', "<?php\nreturn ['csrf' => ['enabled' => false]];\n");
        parent::setUp();
    }

    protected function getBasePath(): string
    {
        return $this->appPath;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->appPath) && is_dir($this->appPath)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->appPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->appPath);
        }
    }

    public function test_extension_boot_registered_channel_dispatches_through_container(): void
    {
        // A real extension ServiceProvider with an overridden boot() that registers its channel
        // via the helper — the exact pattern extension authors write. We invoke boot() directly
        // (the ExtensionManager auto-discovery that *calls* boot() is the extension system's
        // concern, covered by its own tests).
        $provider = new class ($this->getContainer(), $this->stubChannel('stub')) extends ServiceProvider {
            private NotificationChannel $channel;

            public function __construct(ContainerInterface $app, NotificationChannel $channel)
            {
                parent::__construct($app);
                $this->channel = $channel;
            }

            public function boot(ApplicationContext $context): void
            {
                $this->registerNotificationChannel($this->channel);
            }
        };
        $provider->boot($this->app()->getContext());

        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = $this->get(NotificationDispatcher::class);
        $manager = $dispatcher->getChannelManager();

        // The shared, container-wired manager sees the extension channel AND the core one.
        self::assertTrue($manager->hasChannel('stub'));
        self::assertContains('database', $manager->getRegisteredChannelNames());

        // ...and it actually dispatches through it.
        $result = $dispatcher->send($this->notification(), $this->notifiable(), ['stub']);
        self::assertSame('success', $result['channels']['stub']['status']);
    }

    public function test_rewired_consumer_resolves_the_shared_service(): void
    {
        $context = $this->app()->getContext();
        $job = new DispatchNotificationChannels([], $context);

        $method = new \ReflectionMethod($job, 'resolveNotificationService');
        $method->setAccessible(true);

        // The consumer returns the container's shared service, not a locally-built one.
        self::assertSame($this->get(NotificationService::class), $method->invoke($job));
    }

    private function stubChannel(string $name): NotificationChannel
    {
        return new class ($name) implements NotificationChannel {
            public function __construct(private string $name)
            {
            }

            public function getChannelName(): string
            {
                return $this->name;
            }

            public function send(Notifiable $notifiable, array $data): bool
            {
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
        };
    }

    private function notification(): Notification
    {
        return new Notification('test_type', 'Subject', 'user', 'id-1', ['body' => 'hi'], 'u_1', '1');
    }

    private function notifiable(): Notifiable
    {
        return new class implements Notifiable {
            public function routeNotificationFor(string $channel)
            {
                return 'route';
            }

            public function getNotifiableId(): string
            {
                return 'id-1';
            }

            public function getNotifiableType(): string
            {
                return 'user';
            }

            public function shouldReceiveNotification(string $notificationType, string $channel): bool
            {
                return true;
            }

            public function getNotificationPreferences(): array
            {
                return [];
            }
        };
    }
}
