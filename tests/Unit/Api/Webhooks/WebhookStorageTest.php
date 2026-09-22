<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Webhooks;

use Glueful\Api\Webhooks\Webhook;
use Glueful\Api\Webhooks\WebhookDelivery;
use Glueful\Api\Webhooks\WebhookDispatcher;
use Glueful\Api\Webhooks\WebhookSubscription;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;

/**
 * Webhook storage against a real (SQLite file) database:
 * - deleting a subscription left its delivery rows behind, unreachable from any screen;
 * - `api.webhooks.cleanup` (keep_successful_days, keep_failed_days) was read by nothing, so
 *   delivery rows, payloads included, were kept for ever.
 */
final class WebhookStorageTest extends TestCase
{
    private string $appPath;
    private ApplicationContext $context;
    private Connection $db;

    protected function setUp(): void
    {
        RouteManifest::reset();
        $this->appPath = sys_get_temp_dir() . '/glueful-webhooks-' . uniqid('', true);
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
        ];
        foreach ($files as $name => $body) {
            file_put_contents("{$cfg}/{$name}.php", "<?php\nreturn {$body};\n");
        }
        $this->context = Framework::create($this->appPath)->boot(allowReboot: true)->getContext();
        $this->db = Connection::fromContext($this->context);
        Webhook::reset();
        Webhook::setContext($this->context);
        $dispatcher = new WebhookDispatcher($this->db, null, $this->context);
        (new \ReflectionMethod($dispatcher, 'ensureTables'))->invoke($dispatcher);
    }

    protected function tearDown(): void
    {
        Webhook::reset();
        exec('rm -rf ' . escapeshellarg($this->appPath));
    }

    private function subscription(): WebhookSubscription
    {
        return Webhook::subscribe(['entry.published'], 'https://example.com/hook');
    }

    private function delivery(WebhookSubscription $sub, string $status, string $age): int
    {
        $at = date('Y-m-d H:i:s', strtotime($age));

        return $this->db->table('webhook_deliveries')->insert([
            'uuid' => substr(bin2hex(random_bytes(8)), 0, 16),
            'subscription_id' => $sub->id,
            'event' => 'entry.published',
            'payload' => '{}',
            'status' => $status,
            'attempts' => 1,
            'delivered_at' => $status === WebhookDelivery::STATUS_DELIVERED ? $at : null,
            'created_at' => $at,
        ]);
    }

    private function remaining(): int
    {
        return (int) $this->db->table('webhook_deliveries')->count();
    }

    public function testDeletingASubscriptionDeletesItsDeliveries(): void
    {
        $gone = $this->subscription();
        $kept = $this->subscription();
        $this->delivery($gone, WebhookDelivery::STATUS_DELIVERED, '-1 hour');
        $this->delivery($gone, WebhookDelivery::STATUS_FAILED, '-1 hour');
        $this->delivery($kept, WebhookDelivery::STATUS_DELIVERED, '-1 hour');

        $gone->delete();

        self::assertSame(0, (int) $this->db->table('webhook_deliveries')
            ->where('subscription_id', $gone->id)->count());
        self::assertSame(1, $this->remaining(), 'another subscription\'s history is untouched');
    }

    public function testCleanupKeepsDeliveriesOnlyAsLongAsTheConfigSays(): void
    {
        // Stock retention: delivered 7 days, failed 30 days.
        $sub = $this->subscription();
        $this->delivery($sub, WebhookDelivery::STATUS_DELIVERED, '-8 days');   // removed
        $this->delivery($sub, WebhookDelivery::STATUS_DELIVERED, '-2 days');   // kept
        $this->delivery($sub, WebhookDelivery::STATUS_FAILED, '-31 days');     // removed
        $this->delivery($sub, WebhookDelivery::STATUS_FAILED, '-8 days');      // kept
        $this->delivery($sub, WebhookDelivery::STATUS_PENDING, '-60 days');    // never by age
        $this->delivery($sub, WebhookDelivery::STATUS_RETRYING, '-60 days');   // never by age

        $removed = Webhook::cleanup();

        self::assertSame(['delivered' => 1, 'failed' => 1], $removed);
        self::assertSame(4, $this->remaining());
    }

    public function testTheScheduledCleanupJobRunsTheSameRetention(): void
    {
        $sub = $this->subscription();
        $this->delivery($sub, WebhookDelivery::STATUS_DELIVERED, '-8 days');
        $this->delivery($sub, WebhookDelivery::STATUS_DELIVERED, '-1 day');

        (new \Glueful\Api\Webhooks\Jobs\WebhookCleanupJob([], $this->context))->handle();

        self::assertSame(1, $this->remaining());
    }

    public function testCleanupOnASiteThatNeverUsedWebhooksDoesNothing(): void
    {
        $this->db->getPDO()->exec('DROP TABLE webhook_deliveries');

        self::assertSame(['delivered' => 0, 'failed' => 0], Webhook::cleanup());
    }
}
