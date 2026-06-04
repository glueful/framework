<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Permissions;

use Glueful\Auth\UserIdentity;
use Glueful\Framework;
use Glueful\Interfaces\Permission\PermissionProviderInterface;
use Glueful\Permissions\Catalog\{Permission, PermissionRegistry, Role};
use Glueful\Permissions\{Context, Gate, PermissionManager, Vote};
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Real-boot coverage for the unified enforcement path: the Gate has the RegistryRoleVoter
 * wired (Task 10), and PermissionManager::can() behaves correctly across provider modes and
 * the no-provider fallback (Task 12). (No shared IntegrationTestCase exists in this repo.)
 */
final class EnforcementWiringTest extends TestCase
{
    private string $appPath;
    private ContainerInterface $container;
    private PermissionRegistry $registry;
    private PermissionManager $manager;

    private const CORE = [
        'system.access' => '', 'users.view' => '', 'users.create' => '',
        'users.edit' => '', 'users.delete' => '',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->appPath = sys_get_temp_dir() . '/glueful-enf-' . uniqid();
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

        $app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->container = $app->getContainer();
        $this->registry = $this->container->get(PermissionRegistry::class);
        $this->registry->register(Permission::define('blog.publish'), 'vendor/blog');
        $this->registry->registerRole(Role::define('blog.editor')->grants(['blog.publish']), 'vendor/blog');

        $this->manager = $this->container->get('permission.manager');
        $this->manager->clearProvider();
        $this->manager->setPermissionsConfig(['provider_mode' => 'replace', 'strategy' => 'affirmative']);
    }

    protected function tearDown(): void
    {
        $this->manager->clearProvider();
        $this->manager->setPermissionsConfig(['provider_mode' => 'replace', 'strategy' => 'affirmative']);
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

    /** @return PermissionProviderInterface&\PHPUnit\Framework\MockObject\MockObject */
    private function providerThatReturns(bool $result): PermissionProviderInterface
    {
        $p = $this->createMock(PermissionProviderInterface::class);
        $p->method('getProviderInfo')->willReturn(['name' => 'fake']);
        $p->method('getAvailablePermissions')->willReturn(self::CORE);
        $p->method('can')->willReturn($result);
        return $p;
    }

    public function test_gate_has_registry_role_voter_wired(): void
    {
        /** @var Gate $gate */
        $gate = $this->container->get(Gate::class);
        $decision = $gate->decide(new UserIdentity('u1', ['blog.editor']), 'blog.publish', null, new Context());
        self::assertSame(Vote::GRANT, $decision);
    }

    public function test_no_provider_falls_back_to_registry_role(): void
    {
        self::assertTrue($this->manager->can('u1', 'blog.publish', 'system', ['roles' => ['blog.editor']]));
    }

    public function test_no_provider_no_roles_denies(): void
    {
        self::assertFalse($this->manager->can('u1', 'blog.publish', 'system', ['roles' => []]));
    }

    public function test_replace_mode_uses_provider(): void
    {
        $this->manager->setPermissionsConfig(['provider_mode' => 'replace', 'strategy' => 'affirmative']);
        $this->manager->setProvider($this->providerThatReturns(true), []);
        self::assertTrue($this->manager->can('u1', 'blog.publish', 'system', []));
    }

    public function test_combine_mode_composes_provider_and_gate(): void
    {
        $this->manager->setPermissionsConfig(['provider_mode' => 'combine', 'strategy' => 'affirmative']);

        // Provider abstains (can() === false → ABSTAIN in combine); Gate grants via declared role.
        $this->manager->setProvider($this->providerThatReturns(false), []);
        self::assertTrue(
            $this->manager->can('u1', 'blog.publish', 'system', ['roles' => ['blog.editor']]),
            'combine: provider abstains, Gate grants via declared role'
        );

        // Provider grants → composes to grant even without any role.
        $this->manager->setProvider($this->providerThatReturns(true), []);
        self::assertTrue($this->manager->can('u1', 'unrelated.thing', 'system', ['roles' => []]));
    }
}
