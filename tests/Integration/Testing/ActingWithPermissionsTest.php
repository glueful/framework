<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Testing;

use Glueful\Permissions\Catalog\{Permission, PermissionRegistry, Role};
use Glueful\Permissions\Helpers\PermissionHelper;
use Glueful\Testing\TestCase;

/**
 * Exercises the actingWithPermissions/actingWithRoles helpers on Glueful\Testing\TestCase by
 * booting a minimal app and asserting authorization decisions via PermissionHelper.
 */
final class ActingWithPermissionsTest extends TestCase
{
    private string $appPath;

    protected function setUp(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-acting-' . uniqid();
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        file_put_contents($cfg . '/app.php', "<?php\nreturn ['name' => 'Test', 'env' => 'testing', 'debug' => true];\n");
        file_put_contents(
            $cfg . '/database.php',
            "<?php\nreturn ['engine' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]];\n"
        );
        file_put_contents($cfg . '/cache.php', "<?php\nreturn ['enabled' => true, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]];\n");
        file_put_contents($cfg . '/security.php', "<?php\nreturn ['csrf' => ['enabled' => false]];\n");
        file_put_contents($cfg . '/session.php', "<?php\nreturn ['jwt_key' => 'test'];\n");
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
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->appPath);
        }
    }

    public function test_acting_with_permissions_grants_them_to_acting_user_only(): void
    {
        $uuid = $this->actingWithPermissions(['blog.publish'], 'u1');

        self::assertTrue(PermissionHelper::hasPermission($uuid, 'blog.publish', 'system'));
        self::assertFalse(PermissionHelper::hasPermission($uuid, 'blog.delete', 'system'));
        // Regression: a different user is NOT granted the acting user's permissions.
        self::assertFalse(PermissionHelper::hasPermission('someone-else', 'blog.publish', 'system'));
    }

    public function test_acting_with_roles_resolves_declared_grants(): void
    {
        /** @var PermissionRegistry $registry */
        $registry = $this->get(PermissionRegistry::class);
        $registry->register(Permission::define('blog.publish'), 'vendor/blog');
        $registry->registerRole(Role::define('blog.editor')->grants(['blog.publish']), 'vendor/blog');

        $uuid = $this->actingWithRoles(['blog.editor'], 'u1');

        self::assertTrue(PermissionHelper::hasPermission($uuid, 'blog.publish', 'system'));
    }

    public function test_acting_with_unknown_role_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->actingWithRoles(['nope.not.declared']);
    }
}
