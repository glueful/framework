<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Commands\Fields;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\Commands\Fields\WhitelistCheckCommand;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;

/**
 * Verifies that fields:whitelist-check inspects real Router state instead of
 * the placeholder route data that shipped before TG-3.
 *
 * Exercises {@see WhitelistCheckCommand::analyzeWhitelistCompliance()} through
 * reflection so the test stays focused on the analyzer's classification logic
 * without dragging in the Symfony Console output layer.
 */
final class WhitelistCheckCommandTest extends TestCase
{
    private Router $router;
    private WhitelistCheckCommand $command;

    protected function setUp(): void
    {
        parent::setUp();

        $context = new ApplicationContext(sys_get_temp_dir() . '/whitelist_check_test_' . uniqid('', true));
        (new RouteCache($context))->clear();

        $container = new class implements ContainerInterface {
            /** @var array<string, mixed> */
            private array $services = [];

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }

            public function get(string $id): mixed
            {
                if ($this->has($id)) {
                    return $this->services[$id];
                }
                throw new class("Service '{$id}' not found") extends \RuntimeException implements
                    \Psr\Container\NotFoundExceptionInterface {
                };
            }

            public function set(string $id, mixed $service): void
            {
                $this->services[$id] = $service;
            }
        };
        $container->set(ApplicationContext::class, $context);

        $this->router = new Router($container);
        $this->command = new WhitelistCheckCommand();
    }

    public function testAnalyzerIteratesRealRoutesAndClassifiesWhitelistConfiguration(): void
    {
        $this->router->get('/api/users', fn() => null)->name('api.users.index');

        $this->router->get('/api/posts/{id}', fn() => null)
            ->name('api.posts.show')
            ->setFieldsConfig(['allowed' => ['id', 'title', 'body'], 'strict' => true]);

        $this->router->get('/api/admin/users', fn() => null)->name('api.admin.users');

        $analysis = $this->runAnalyzer(strict: false, security: true);

        self::assertSame(3, $analysis['summary']['total_routes']);
        self::assertSame(1, $analysis['summary']['whitelist_configured']);
        self::assertSame(2, $analysis['summary']['no_whitelist']);
    }

    public function testAdminRouteWithoutWhitelistRaisesCriticalSecurityIssue(): void
    {
        $this->router->get('/api/admin/users', fn() => null)->name('api.admin.users');

        $analysis = $this->runAnalyzer(strict: false, security: true);

        self::assertGreaterThanOrEqual(1, $analysis['security']['critical_issues']);

        $types = array_column($analysis['security']['issues'], 'type');
        self::assertContains('ADMIN_NO_WHITELIST', $types);
    }

    public function testNonStrictApiWhitelistRaisesLowSeverityIssue(): void
    {
        $this->router->get('/api/users', fn() => null)
            ->name('api.users.index')
            ->setFieldsConfig(['allowed' => ['id', 'name'], 'strict' => false]);

        $analysis = $this->runAnalyzer(strict: false, security: true);

        $types = array_column($analysis['security']['issues'], 'type');
        self::assertContains('NON_STRICT_WHITELIST', $types);
    }

    public function testStrictWhitelistOnApiRouteIsClean(): void
    {
        $this->router->get('/api/users', fn() => null)
            ->name('api.users.index')
            ->setFieldsConfig(['allowed' => ['id', 'name'], 'strict' => true]);

        $analysis = $this->runAnalyzer(strict: false, security: true);

        self::assertSame(0, $analysis['security']['critical_issues']);
        self::assertSame(0, $analysis['security']['medium_issues']);
        self::assertSame(0, $analysis['security']['low_issues']);
        self::assertSame(1, $analysis['summary']['compliance_passes']);
    }

    public function testSpecificRoutesOptionFiltersAnalysis(): void
    {
        $this->router->get('/api/users', fn() => null)->name('api.users.index');
        $this->router->get('/api/posts', fn() => null)->name('api.posts.index');

        $analysis = $this->runAnalyzer(
            strict: false,
            security: false,
            specificRoutes: ['api.users.index']
        );

        self::assertSame(1, $analysis['summary']['total_routes']);
        self::assertCount(1, $analysis['routes']);
        self::assertSame('api.users.index', $analysis['routes'][0]['name']);
    }

    public function testReferencePatternsDoNotIncludeFabricatedFrequencyStats(): void
    {
        $analysis = $this->runAnalyzer(strict: false, security: false);

        self::assertArrayHasKey('common_patterns', $analysis);
        self::assertArrayHasKey('most_requested_fields', $analysis['common_patterns']);
        self::assertArrayHasKey('sensitive_fields', $analysis['common_patterns']);
        self::assertArrayHasKey('admin_only_fields', $analysis['common_patterns']);
        self::assertArrayNotHasKey('pattern_frequency', $analysis['common_patterns']);
    }

    /**
     * @param array<string> $specificRoutes
     * @return array<string, mixed>
     */
    private function runAnalyzer(bool $strict, bool $security, array $specificRoutes = []): array
    {
        $method = new ReflectionMethod($this->command, 'analyzeWhitelistCompliance');
        /** @var array<string, mixed> $result */
        $result = $method->invoke($this->command, $this->router, $specificRoutes, $strict, $security);

        return $result;
    }
}
