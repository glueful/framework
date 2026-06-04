<?php

declare(strict_types=1);

namespace Glueful\Testing;

use Glueful\Framework;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Psr\Container\ContainerInterface;

abstract class TestCase extends PHPUnitTestCase
{
    protected ?\Glueful\Application $app = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApplication();
    }

    protected function tearDown(): void
    {
        // Reset the static permission provider so acting* helpers don't leak across tests.
        if ($this->app !== null && $this->getContainer()->has('permission.manager')) {
            $this->get('permission.manager')->clearProvider();
        }
        $this->resetFrameworkState();
        $this->app = null;
        parent::tearDown();
    }

    /**
     * Authorize the given permission slugs for the rest of the test by installing an in-memory
     * permission provider in 'replace' mode. ONLY $userUuid is granted them. Returns the uuid.
     *
     * @param string[] $permissions
     */
    protected function actingWithPermissions(array $permissions, string $userUuid = 'test-user'): string
    {
        $manager = $this->get('permission.manager');
        $manager->setPermissionsConfig(['provider_mode' => 'replace', 'strategy' => 'affirmative']);
        $manager->setProvider(new \Glueful\Testing\InMemoryPermissionProvider([$userUuid => $permissions]), []);
        return $userUuid;
    }

    /**
     * Authorize via declared roles: resolves each role's granted permissions from the
     * PermissionRegistry, then grants them to the acting user. Returns the uuid.
     *
     * @param string[] $roleSlugs
     * @throws \InvalidArgumentException if a role is not declared in the catalog (so a typo'd
     *         role surfaces as an error, not a silent denial).
     */
    protected function actingWithRoles(array $roleSlugs, string $userUuid = 'test-user'): string
    {
        /** @var \Glueful\Permissions\Catalog\PermissionRegistry $registry */
        $registry = $this->get(\Glueful\Permissions\Catalog\PermissionRegistry::class);
        $map = $registry->rolePermissionMap();

        $permissions = [];
        foreach ($roleSlugs as $role) {
            if (!array_key_exists($role, $map)) {
                throw new \InvalidArgumentException(sprintf(
                    'actingWithRoles(): role "%s" is not declared in the permission catalog.',
                    $role
                ));
            }
            foreach ($map[$role] as $perm) {
                $permissions[] = $perm;
            }
        }

        return $this->actingWithPermissions(array_values(array_unique($permissions)), $userUuid);
    }

    /**
     * Create application instance for testing
     * Override this method to customize the application setup
     */
    protected function createApplication(): \Glueful\Application
    {
        $framework = Framework::create($this->getBasePath())
            ->withEnvironment('testing');

        return $framework->boot();
    }

    /**
     * Get application base path
     * Override this method if your tests are in a different location
     */
    protected function getBasePath(): string
    {
        return dirname(__DIR__, 3); // Assumes tests/Unit/... structure
    }

    protected function getContainer(): ContainerInterface
    {
        return $this->app->getContainer();
    }

    protected function get(string $id): mixed
    {
        return $this->getContainer()->get($id);
    }

    /**
     * Get the application instance
     */
    protected function app(): \Glueful\Application
    {
        return $this->app;
    }

    /**
     * Check if service exists in container
     */
    protected function has(string $id): bool
    {
        return $this->getContainer()->has($id);
    }

    /**
     * Helper method to refresh the application between tests
     * Useful when you need to reset the entire application state
     */
    protected function refreshApplication(): void
    {
        $this->resetFrameworkState();
        $this->app = $this->createApplication();
    }

    private function resetFrameworkState(): void
    {
        // Reset context caches between tests
        $context = $this->app?->getContext();
        if ($context !== null) {
            $context->clearConfigCache();
            $context->resetRequestState();
        }
    }
}
