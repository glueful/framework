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
        $this->resetFrameworkState();
        $this->app = null;
        parent::tearDown();
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
        // Clear framework globals
        unset(
            $GLOBALS['base_path'],
            $GLOBALS['config_paths'],
            $GLOBALS['container'],
            $GLOBALS['framework_booting'],
            $GLOBALS['framework_bootstrapped'],
            $GLOBALS['configs_loaded'],
            $GLOBALS['config_loader'],
            $GLOBALS['lazy_initializer']
        );

        // Reset static caches in framework helper functions
        if (function_exists('base_path')) {
            base_path('__RESET__');
        }
        if (function_exists('config_path')) {
            config_path('__RESET__');
        }
    }
}
