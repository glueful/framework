<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Container;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Container\Bootstrap\ContainerFactory;
use Glueful\Storage\StorageManager;
use Glueful\Tests\Fixtures\StorageRegistry\DslStorageFactoryProvider;
use Glueful\Tests\Fixtures\StorageRegistry\RecordingStorageDriverFactory;
use PHPUnit\Framework\TestCase;

final class StorageDriverFactoryDslTagTest extends TestCase
{
    public function testDslTaggedStorageDriverFactoryIsCollectedByStorageProvider(): void
    {
        $ctx = $this->contextWithProvider(DslStorageFactoryProvider::class);

        $container = ContainerFactory::create($ctx, false);

        $manager = $container->get(StorageManager::class);
        $this->assertInstanceOf(StorageManager::class, $manager);
        $this->assertInstanceOf(RecordingStorageDriverFactory::class, $manager->drivers()->get('recording'));
        $this->assertTrue($manager->diskExists('recording'));
    }

    private function contextWithProvider(string $providerFqcn): ApplicationContext
    {
        $base = sys_get_temp_dir() . '/glueful-storage-dsl-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents(
            $base . '/config/serviceproviders.php',
            "<?php\nreturn " . var_export(['enabled' => [$providerFqcn]], true) . ";\n"
        );
        file_put_contents(
            $base . '/config/storage.php',
            "<?php\nreturn ['default' => 'recording', 'disks' => ['recording' => ['driver' => 'recording']]];\n"
        );

        $ctx = new ApplicationContext($base, 'testing');
        $ctx->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));

        return $ctx;
    }
}
