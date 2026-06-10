<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Commands\Storage;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Console\Commands\Storage\StorageTestCommand;
use Glueful\Container\Container;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use Glueful\Storage\Contracts\StorageHealthCheckInterface;
use Glueful\Storage\Contracts\StorageDriverRegistryInterface;
use Glueful\Storage\StorageDriverRegistry;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class StorageTestCommandTest extends TestCase
{
    public function testReportsRegisteredAndAvailableForBuiltInLocal(): void
    {
        $report = StorageTestCommand::buildReport(
            StorageDriverRegistry::withBuiltIns(),
            [
                'default' => 'uploads',
                'disks' => ['uploads' => ['driver' => 'local', 'root' => sys_get_temp_dir()]],
            ],
            false
        );

        $this->assertArrayHasKey('uploads', $report);
        $this->assertTrue($report['uploads']['registered']);
        $this->assertTrue($report['uploads']['available']);
        $this->assertFalse($report['uploads']['wrote']);
    }

    public function testReportsMissingDriverCleanlyWithoutThrowing(): void
    {
        $report = StorageTestCommand::buildReport(
            StorageDriverRegistry::withBuiltIns(),
            ['default' => 's3', 'disks' => ['s3' => ['driver' => 's3', 'bucket' => 'b']]],
            false
        );

        $this->assertFalse($report['s3']['registered']);
        $this->assertFalse($report['s3']['available']);
        $this->assertStringContainsString('glueful/storage-s3', (string) $report['s3']['message']);
    }

    public function testRedactsSecretsFromReport(): void
    {
        $report = StorageTestCommand::buildReport(
            StorageDriverRegistry::withBuiltIns(),
            [
                'default' => 's3',
                'disks' => ['s3' => ['driver' => 's3', 'bucket' => 'b', 'secret' => 'TOP', 'key' => 'AKIA']],
            ],
            false
        );
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('TOP', $encoded);
        $this->assertStringNotContainsString('AKIA', $encoded);
    }

    public function testWriteFlagRunsSmokeTestOnMemoryDisk(): void
    {
        $report = StorageTestCommand::buildReport(
            StorageDriverRegistry::withBuiltIns(),
            ['default' => 'mem', 'disks' => ['mem' => ['driver' => 'memory']]],
            true
        );

        $this->assertTrue($report['mem']['wrote']);
        $this->assertTrue($report['mem']['ok']);
    }

    public function testHealthCheckCapabilityIsInvokedReadOnly(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns();
        $registry->register('probed', $this->healthyFactory('probed'));

        $report = StorageTestCommand::buildReport(
            $registry,
            ['default' => 'p', 'disks' => ['p' => ['driver' => 'probed']]],
            false
        );

        $this->assertTrue($report['p']['liveness']);
        $this->assertFalse($report['p']['wrote']);
        $this->assertSame('reachable', $report['p']['message']);
    }

    public function testCommandFailsWhenRequestedDiskDoesNotExist(): void
    {
        $context = $this->contextWithStorageConfig([
            'default' => 'uploads',
            'disks' => ['uploads' => ['driver' => 'memory']],
        ]);
        $container = new Container([
            StorageDriverRegistryInterface::class => new ValueDefinition(
                StorageDriverRegistryInterface::class,
                StorageDriverRegistry::withBuiltIns()
            ),
        ]);

        $tester = new CommandTester(new StorageTestCommand($container, $context));
        $exit = $tester->execute(['disk' => 'typo']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No storage disk found for typo.', $tester->getDisplay());
    }

    private function healthyFactory(string $driver): StorageDriverFactoryInterface
    {
        return new class ($driver) implements StorageDriverFactoryInterface, StorageHealthCheckInterface {
            public function __construct(private string $name)
            {
            }

            public function driver(): string
            {
                return $this->name;
            }

            public function create(array $config): FilesystemOperator
            {
                return new Filesystem(new InMemoryFilesystemAdapter());
            }

            public function available(array $config): bool
            {
                return true;
            }

            public function features(array $config): array
            {
                return [];
            }

            public function check(string $disk, array $diskConfig): array
            {
                return ['ok' => true, 'message' => 'reachable'];
            }
        };
    }

    /**
     * @param array<string, mixed> $storageConfig
     */
    private function contextWithStorageConfig(array $storageConfig): ApplicationContext
    {
        $base = sys_get_temp_dir() . '/glueful-storage-command-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents($base . '/config/storage.php', "<?php\nreturn " . var_export($storageConfig, true) . ";\n");

        $context = new ApplicationContext($base, 'testing');
        $context->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));

        return $context;
    }
}
