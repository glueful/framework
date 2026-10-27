<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Webhooks;

use Glueful\Api\Webhooks\Webhook;
use Glueful\Api\Webhooks\WebhookDispatcher;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Schema\FrameworkDescriptors;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;

/**
 * The webhook tables used to be created lazily inside WebhookDispatcher::dispatch(), so on a
 * fresh database Webhook::subscribe() (and the controller's create action) failed until something
 * had dispatched once. Core now ships the schema as the `webhooks` migration leaf.
 */
final class WebhookMigrationTest extends TestCase
{
    private string $appPath;
    private ApplicationContext $context;
    private Connection $db;

    protected function setUp(): void
    {
        RouteManifest::reset();
        $this->appPath = sys_get_temp_dir() . '/glueful-webhooks-mig-' . uniqid('', true);
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
    }

    protected function tearDown(): void
    {
        Webhook::reset();
        exec('rm -rf ' . escapeshellarg($this->appPath));
    }

    private function migration(): object
    {
        require_once dirname(__DIR__, 4) . '/migrations/webhooks/001_CreateWebhookTables.php';

        return new \Glueful\Migrations\Webhooks\CreateWebhookTables();
    }

    public function testSubscribingWorksOnAFreshlyMigratedDatabaseWithoutAnyDispatch(): void
    {
        $this->migration()->up($this->db->getSchemaBuilder());

        $sub = Webhook::subscribe(['order.*'], 'https://example.com/hook');

        self::assertNotNull($sub->id);
        self::assertSame(1, (int) $this->db->table('webhook_subscriptions')->count());
    }

    public function testTheMigrationCreatesEveryColumnTheModelsUse(): void
    {
        $schema = $this->db->getSchemaBuilder();
        $this->migration()->up($schema);

        foreach (['id', 'uuid', 'url', 'events', 'secret', 'is_active', 'metadata', 'created_at', 'updated_at'] as $c) {
            self::assertTrue($schema->hasColumn('webhook_subscriptions', $c), "webhook_subscriptions.{$c}");
        }
        foreach (
            [
                'id', 'uuid', 'subscription_id', 'event', 'payload', 'status', 'attempts', 'response_code',
                'response_body', 'delivered_at', 'next_retry_at', 'created_at',
            ] as $c
        ) {
            self::assertTrue($schema->hasColumn('webhook_deliveries', $c), "webhook_deliveries.{$c}");
        }
    }

    public function testTheMigrationIsIdempotentOverTablesTheOldLazyPathCreated(): void
    {
        $schema = $this->db->getSchemaBuilder();
        $migration = $this->migration();
        $migration->up($schema);
        Webhook::subscribe(['order.*'], 'https://example.com/hook');

        $migration->up($schema); // a DB whose tables already exist migrates cleanly, data intact

        self::assertSame(1, (int) $this->db->table('webhook_subscriptions')->count());

        $migration->down($schema);
        self::assertFalse($schema->hasTable('webhook_subscriptions'));
        self::assertFalse($schema->hasTable('webhook_deliveries'));
    }

    public function testDispatchNoLongerRunsDdl(): void
    {
        $dispatcher = new WebhookDispatcher(null, $this->context);

        try {
            $dispatcher->dispatch('order.shipped', []);
        } catch (\Throwable) {
            // an unmigrated database is an error now, not a reason to create tables at runtime
        }

        self::assertFalse($this->db->getSchemaBuilder()->hasTable('webhook_subscriptions'));
        self::assertFalse($this->db->getSchemaBuilder()->hasTable('webhook_deliveries'));
    }

    public function testTheLeafIsRegisteredAsACoreFoundationDescriptor(): void
    {
        $root = dirname(__DIR__, 4);
        $found = null;
        foreach (FrameworkDescriptors::all($root) as $d) {
            if ($d->source() === 'glueful/framework:webhooks') {
                $found = $d;
            }
        }

        self::assertNotNull($found, 'webhooks leaf must be registered');
        self::assertSame('migrations/webhooks', $found->relativePath);
        self::assertSame(\Glueful\Database\Migrations\MigrationPriority::FOUNDATION, $found->priority);
        self::assertSame(\Glueful\Extensions\Schema\DescriptorMode::Core, $found->mode);
    }
}
