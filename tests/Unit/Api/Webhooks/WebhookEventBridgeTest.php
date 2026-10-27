<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Webhooks;

use Glueful\Api\Webhooks\Attributes\Webhookable;
use Glueful\Api\Webhooks\Concerns\DispatchesWebhooks;
use Glueful\Api\Webhooks\Webhook;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Events\Contracts\BaseEvent;
use Glueful\Events\EventService;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;

/**
 * WebhookEventListener was never registered, so #[Webhookable] and DispatchesWebhooks did
 * nothing: an event carrying them was dispatched to its listeners and no webhook went out. The
 * trait's webhookOptions() (queue, delay, …) were handed to the dispatcher and then ignored.
 */
final class WebhookEventBridgeTest extends TestCase
{
    private string $appPath;
    private ApplicationContext $context;
    private Connection $db;

    protected function tearDown(): void
    {
        Webhook::reset();
        exec('rm -rf ' . escapeshellarg($this->appPath));
    }

    private function boot(bool $webhooksEnabled = true): void
    {
        RouteManifest::reset();
        $this->appPath = sys_get_temp_dir() . '/glueful-webhooks-bridge-' . uniqid('', true);
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        $files = [
            'app' => "['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true, "
                . "'key' => 'test-key']",
            'database' => "['engine' => 'sqlite', 'sqlite' => ['primary' => '" . $this->appPath . "/w.sqlite'], "
                . "'pooling' => ['enabled' => false]]",
            'cache' => "['enabled' => true, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]",
            'security' => "['csrf' => ['enabled' => false]]",
            'session' => "['jwt_key' => 'test']",
            'queue' => "['default' => 'database', 'connections' => ['database' => ['driver' => 'database', "
                . "'table' => 'queue_jobs', 'queue' => 'default']]]",
            'api' => "['webhooks' => ['enabled' => " . ($webhooksEnabled ? 'true' : 'false') . "]]",
        ];
        foreach ($files as $name => $body) {
            file_put_contents("{$cfg}/{$name}.php", "<?php\nreturn {$body};\n");
        }
        $this->context = Framework::create($this->appPath)->boot(allowReboot: true)->getContext();
        $this->db = Connection::fromContext($this->context);
        $root = dirname(__DIR__, 4);
        require_once $root . '/migrations/webhooks/001_CreateWebhookTables.php';
        require_once $root . '/migrations/queue/001_CreateQueueSystemTables.php';
        (new \Glueful\Migrations\Webhooks\CreateWebhookTables())->up($this->db->getSchemaBuilder());
        (new \Glueful\Migrations\Queue\CreateQueueSystemTables())->up($this->db->getSchemaBuilder());
        Webhook::reset();
        Webhook::setContext($this->context);
    }

    private function events(): EventService
    {
        return app($this->context, EventService::class);
    }

    /** @return list<array<string, mixed>> */
    private function deliveries(): array
    {
        return $this->db->table('webhook_deliveries')->get();
    }

    /** @return list<array<string, mixed>> */
    private function jobs(): array
    {
        return $this->db->table('queue_jobs')->get();
    }

    public function testAWebhookableEventIsDispatchedAsAWebhook(): void
    {
        $this->boot();
        Webhook::subscribe(['order.shipped'], 'https://example.com/hook');

        $this->events()->dispatch(new BridgeOrderShipped('ord_1'));

        $deliveries = $this->deliveries();
        self::assertCount(1, $deliveries);
        self::assertSame('order.shipped', $deliveries[0]['event']);
        self::assertStringContainsString('ord_1', (string) $deliveries[0]['payload']);
        self::assertCount(1, $this->jobs());
        self::assertSame('webhooks', $this->jobs()[0]['queue']);
    }

    public function testATraitEventIsDispatchedWithItsOptionsHonoured(): void
    {
        $this->boot();
        Webhook::subscribe(['invoice.*'], 'https://example.com/hook');

        $this->events()->dispatch(new BridgeInvoicePaid('inv_1'));

        $deliveries = $this->deliveries();
        self::assertCount(1, $deliveries);
        self::assertSame('invoice.paid', $deliveries[0]['event']);
        $jobs = $this->jobs();
        self::assertCount(1, $jobs);
        self::assertSame('priority-hooks', $jobs[0]['queue'], 'webhookOptions() queue is honoured');
        self::assertGreaterThan(
            time() + 60,
            strtotime((string) $jobs[0]['available_at']),
            'webhookOptions() delay is honoured'
        );
    }

    public function testAnUnmarkedEventSendsNothing(): void
    {
        $this->boot();
        Webhook::subscribe(['*'], 'https://example.com/hook');

        $this->events()->dispatch(new BridgePlainEvent());

        self::assertCount(0, $this->deliveries());
    }

    public function testTheBridgeIsNotRegisteredWhenWebhooksAreDisabled(): void
    {
        $this->boot(webhooksEnabled: false);

        self::assertSame([], $this->events()->getListeners(BridgeOrderShipped::class));
    }
}

#[Webhookable(event: 'order.shipped')]
final class BridgeOrderShipped extends BaseEvent
{
    public function __construct(public readonly string $orderId)
    {
        parent::__construct();
    }
}

final class BridgeInvoicePaid extends BaseEvent
{
    use DispatchesWebhooks;

    public function __construct(public readonly string $invoiceId)
    {
        parent::__construct();
    }

    public function webhookEventName(): string
    {
        return 'invoice.paid';
    }

    /** @return array<string, mixed> */
    public function webhookOptions(): array
    {
        return ['queue' => 'priority-hooks', 'delay' => 300];
    }
}

final class BridgePlainEvent extends BaseEvent
{
}
