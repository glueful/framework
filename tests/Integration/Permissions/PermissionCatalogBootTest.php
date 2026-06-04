<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Permissions;

use Glueful\Framework;
use Glueful\Interfaces\Permission\PermissionStandards;
use Glueful\Permissions\Catalog\PermissionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Real-boot coverage for the catalog wiring: the registry is a shared singleton in the
 * container, and the fail-fast aggregate pass runs during boot (core permissions present).
 * (No shared IntegrationTestCase exists in this repo, so this models FrameworkBootTest.)
 */
final class PermissionCatalogBootTest extends TestCase
{
    private string $appPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appPath = sys_get_temp_dir() . '/glueful-perm-' . uniqid();
        $configPath = $this->appPath . '/config';
        mkdir($configPath, 0755, true);

        file_put_contents($configPath . '/app.php', "<?php\nreturn ['name' => 'Test', 'env' => 'testing', 'debug' => true];\n");
        file_put_contents(
            $configPath . '/database.php',
            "<?php\nreturn ['engine' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]];\n"
        );
        file_put_contents($configPath . '/cache.php', "<?php\nreturn ['enabled' => true, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]];\n");
        file_put_contents($configPath . '/security.php', "<?php\nreturn ['csrf' => ['enabled' => false]];\n");
        file_put_contents($configPath . '/session.php', "<?php\nreturn ['jwt_key' => 'test'];\n");
    }

    protected function tearDown(): void
    {
        if (is_dir($this->appPath)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->appPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->appPath);
        }
        parent::tearDown();
    }

    public function test_registry_is_shared_and_core_permissions_aggregated(): void
    {
        $app = Framework::create($this->appPath)->boot(allowReboot: true);
        $container = $app->getContainer();

        $a = $container->get(PermissionRegistry::class);
        $b = $container->get(PermissionRegistry::class);
        self::assertInstanceOf(PermissionRegistry::class, $a);
        self::assertSame($a, $b, 'PermissionRegistry must be a shared singleton');

        // aggregatePermissionCatalog() ran during boot → framework core permissions present.
        self::assertTrue($a->has(PermissionStandards::PERMISSION_SYSTEM_ACCESS));
        self::assertTrue($a->has(PermissionStandards::PERMISSION_USERS_VIEW));
    }
}
