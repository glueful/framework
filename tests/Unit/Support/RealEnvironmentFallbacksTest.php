<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support;

use Glueful\Database\ORM\Casts\AsEncryptedString;
use Glueful\Queue\Jobs\QueueMaintenance;
use PHPUnit\Framework\TestCase;

/**
 * Two remaining direct $_ENV reads with no fallback: the encryption cast's key and queue
 * maintenance's config fallback. Both must see a variable the real process environment holds,
 * exactly as env() does.
 */
final class RealEnvironmentFallbacksTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['ENCRYPTION_KEY'], $_SERVER['ENCRYPTION_KEY']);
        putenv('ENCRYPTION_KEY');
        unset($_ENV['QUEUE_PROBE_SETTING'], $_SERVER['QUEUE_PROBE_SETTING']);
        putenv('QUEUE_PROBE_SETTING');
        (new \ReflectionProperty(AsEncryptedString::class, 'key'))->setValue(null, null);
    }

    public function testTheEncryptionKeyIsReadFromTheRealEnvironment(): void
    {
        (new \ReflectionProperty(AsEncryptedString::class, 'key'))->setValue(null, null);
        unset($_ENV['ENCRYPTION_KEY'], $_SERVER['ENCRYPTION_KEY']);
        putenv('ENCRYPTION_KEY=' . str_repeat('k', 32));

        $cast = new AsEncryptedString();
        $model = $this->createMock(\Glueful\Database\ORM\Model::class);
        $stored = $cast->set($model, 'secret', 'plain', []);

        self::assertNotSame('plain', $stored);
        self::assertSame('plain', $cast->get($model, 'secret', $stored, []));
    }

    public function testQueueMaintenanceConfigFallsBackToTheRealEnvironment(): void
    {
        unset($_ENV['QUEUE_PROBE_SETTING'], $_SERVER['QUEUE_PROBE_SETTING']);
        putenv('QUEUE_PROBE_SETTING=from-real-env');
        $maintenance = (new \ReflectionClass(QueueMaintenance::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(QueueMaintenance::class, 'context'))->setValue($maintenance, null);
        $getConfig = new \ReflectionMethod(QueueMaintenance::class, 'getConfig');

        self::assertSame('from-real-env', $getConfig->invoke($maintenance, 'queue.probe.setting', 'default'));
    }
}
