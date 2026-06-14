<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\ReferenceAdoption;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Controllers\ResourceController;
use Glueful\Framework;
use Glueful\Helpers\Utils;
use Glueful\Repository\RepositoryFactory;
use Glueful\Repository\ResourceRepository;
use Glueful\Routing\RouteManifest;
use Glueful\Routing\Router;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Characterization + reference-adoption guard for {@see ResourceController}'s
 * read-list ({@see ResourceController::index()}) and delete
 * ({@see ResourceController::destroy()}) endpoints — the phased "adopt typed
 * RESPONSE DTOs as a reference example" work.
 *
 * These tests are written FIRST, against the UNMODIFIED controller, and are the
 * SOLE arbiter of the contract. They pin the exact enveloped bodies (status +
 * every decoded JSON key, order, and value).
 *
 * --- index (GET /data/{table}) ---
 * Pre-migration: `Response::successWithMeta($data, $meta, 'Resource list
 * retrieved successfully')`, where `$meta` is the repository's `paginate()`
 * result with `data` removed. `successWithMeta` FLATTENS every meta key into the
 * envelope root. The repository (BaseRepository -> QueryBuilder pagination) emits
 * meta keys:
 *     current_page, per_page, total, last_page, has_more, from, to,
 *     execution_time_ms
 * so the full index body key set is:
 *     success, message, data, current_page, per_page, total, last_page,
 *     has_more, from, to, execution_time_ms
 *
 * A `PaginatedResponse` (via `Response::paginated()`) instead emits:
 *     success, message, data, current_page, per_page, total, total_pages,
 *     has_next_page, has_previous_page
 *
 * The two key sets DIFFER: index has `last_page, has_more, from, to,
 * execution_time_ms` (absent from paginated) and LACKS `total_pages,
 * has_next_page, has_previous_page`. Migrating index to PaginatedResponse would
 * DROP 5 keys and ADD 3 — a breaking contract change. So index is LEFT UNCHANGED
 * and this test pins its as-is shape.
 *
 * --- destroy (DELETE /data/{table}/{uuid}) ---
 * Pre-migration: `Response::success(['affected' => 1, 'success' => true,
 * 'message' => 'Record deleted successfully'], 'Resource deleted successfully')`.
 * Note the duplication: `data` carries its OWN `success` + `message` keys,
 * distinct from the OUTER envelope's `success` flag + `message`. The migration to
 * {@see \Glueful\Controllers\DTOs\ResourceDeletedData} must keep BOTH the
 * data-level `success`/`message` AND the (different) envelope message byte-for-byte.
 *
 * A test-local controller subclass overrides `can()` to grant access so the
 * success branch is exercised without standing up the full permission/auth stack,
 * and a deterministic RepositoryFactory double feeds fixed rows — isolating the
 * controller + router envelope path (the part being migrated).
 */
final class ResourceApiTest extends TestCase
{
    private string $appPath;
    private Application $app;
    private ApplicationContext $context;
    private Router $router;

    /** The fixed page of rows the repository double returns from paginate(). */
    public const ROWS = [
        ['id' => 1, 'uuid' => 'row-1', 'name' => 'Alpha'],
        ['id' => 2, 'uuid' => 'row-2', 'name' => 'Beta'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        RouteManifest::reset();
        $this->bootFramework();
        $this->overrideRepositoryFactory();
        Utils::setContext($this->context);

        $this->registerTestController();

        $this->router = $this->app->getContainer()->get(Router::class);
        $this->router->get('/test/data/{table}', [TestResourceController::class, 'index']);
        $this->router->delete('/test/data/{table}/{uuid}', [TestResourceController::class, 'destroy']);
    }

    protected function tearDown(): void
    {
        Utils::setContext(null);
        if (isset($this->appPath) && is_dir($this->appPath)) {
            $this->recursiveRemoveDirectory($this->appPath);
        }
        parent::tearDown();
    }

    public function test_index_returns_byte_identical_success_with_meta_envelope(): void
    {
        $request = Request::create('/test/data/widgets?page=1&per_page=25', 'GET');
        $request->attributes->set('table', 'widgets');
        $this->authenticate($request);

        $response = $this->router->dispatch($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);

        // Exact key set + ORDER of the flattened successWithMeta envelope.
        self::assertSame(
            [
                'success',
                'message',
                'data',
                'current_page',
                'per_page',
                'total',
                'last_page',
                'has_more',
                'from',
                'to',
                'execution_time_ms',
            ],
            array_keys($body)
        );

        self::assertTrue($body['success']);
        self::assertSame('Resource list retrieved successfully', $body['message']);
        self::assertSame(self::ROWS, $body['data']);
        self::assertSame(1, $body['current_page']);
        self::assertSame(25, $body['per_page']);
        self::assertSame(2, $body['total']);
        self::assertSame(1, $body['last_page']);
        self::assertFalse($body['has_more']);
        self::assertSame(1, $body['from']);
        self::assertSame(2, $body['to']);
        self::assertSame(0, $body['execution_time_ms']);

        // Guard the as-is contract: PaginatedResponse-only keys MUST NOT appear —
        // a migration to PaginatedResponse would introduce them (a breaking change).
        self::assertArrayNotHasKey('total_pages', $body);
        self::assertArrayNotHasKey('has_next_page', $body);
        self::assertArrayNotHasKey('has_previous_page', $body);
    }

    public function test_destroy_returns_byte_identical_success_envelope(): void
    {
        $request = Request::create('/test/data/widgets/row-1', 'DELETE');
        $request->attributes->set('table', 'widgets');
        $request->attributes->set('uuid', 'row-1');
        $this->authenticate($request);

        $response = $this->router->dispatch($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);

        // The exact pre-migration envelope:
        //   Response::success(
        //     ['affected'=>1,'success'=>true,'message'=>'Record deleted successfully'],
        //     'Resource deleted successfully'
        //   )
        // The OUTER envelope message ('Resource deleted successfully') differs from
        // the data-level message ('Record deleted successfully'); both are pinned.
        self::assertSame([
            'success' => true,
            'message' => 'Resource deleted successfully',
            'data'    => [
                'affected' => 1,
                'success'  => true,
                'message'  => 'Record deleted successfully',
            ],
        ], $body);

        // Explicitly assert the data-level keys survive distinct from the envelope.
        self::assertArrayHasKey('success', $body['data']);
        self::assertArrayHasKey('message', $body['data']);
        self::assertSame('Record deleted successfully', $body['data']['message']);
        self::assertTrue($body['data']['success']);
    }

    /**
     * not-found path stays a Response (unchanged) — exercised for destroy to
     * confirm the migration only touches the success branch.
     */
    public function test_destroy_not_found_stays_response(): void
    {
        $request = Request::create('/test/data/widgets/missing', 'DELETE');
        $request->attributes->set('table', 'widgets');
        $request->attributes->set('uuid', 'missing');
        $this->authenticate($request);

        $response = $this->router->dispatch($request);

        self::assertSame(404, $response->getStatusCode());
    }

    private function authenticate(Request $request): void
    {
        $request->attributes->set('user', ['uuid' => 'user-1', 'role' => 'admin', 'info' => []]);
        $container = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->load(['request' => $request]);
    }

    /**
     * Swap RepositoryFactory for a deterministic double BEFORE the controller
     * resolves it. The controller receives RepositoryFactory as a constructor arg
     * the router resolves from the container; overriding the definition makes
     * get() return the double.
     */
    private function overrideRepositoryFactory(): void
    {
        $container = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $container);

        $context = $this->context;

        $repository = new class ('widgets', $context) extends ResourceRepository {
            public function __construct(private string $tbl, ApplicationContext $ctx)
            {
                // Skip parent constructor: no real Connection is needed because
                // every method reached below is overridden.
            }

            /** @return array<string, mixed>|null */
            public function find(string $uuid): ?array
            {
                foreach (ResourceApiTest::ROWS as $row) {
                    if ($row['uuid'] === $uuid) {
                        return $row;
                    }
                }
                return null;
            }

            public function delete(string $uuid): bool
            {
                return $this->find($uuid) !== null;
            }

            /**
             * @param array<string, mixed> $conditions
             * @param array<string, string> $orderBy
             * @param array<string> $fields
             * @return array<string, mixed>
             */
            public function paginate(
                int $page,
                int $perPage,
                array $conditions = [],
                array $orderBy = [],
                array $fields = []
            ): array {
                $total = count(ResourceApiTest::ROWS);
                $lastPage = (int) ceil($total / $perPage);
                return [
                    'data' => ResourceApiTest::ROWS,
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => $lastPage,
                    'has_more' => $page < $lastPage,
                    'from' => 1,
                    'to' => $total,
                    'execution_time_ms' => 0,
                ];
            }
        };

        $factory = new class ($repository, $context) extends RepositoryFactory {
            public function __construct(
                private \Glueful\Repository\Interfaces\RepositoryInterface $repo,
                ApplicationContext $ctx
            ) {
                // Skip parent constructor: no Connection needed for the double.
            }

            public function getRepository(string $resource): \Glueful\Repository\Interfaces\RepositoryInterface
            {
                return $this->repo;
            }
        };

        $container->load([RepositoryFactory::class => $factory]);
    }

    /**
     * Build the test controller with the overridden RepositoryFactory and register
     * it in the container so the router can resolve [TestResourceController::class].
     */
    private function registerTestController(): void
    {
        $container = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $container);

        $factory = $container->get(RepositoryFactory::class);
        $controller = new TestResourceController($this->context, $factory);

        $container->load([TestResourceController::class => $controller]);
    }

    private function bootFramework(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-resourcerefadopt-' . uniqid();
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        file_put_contents($cfg . '/app.php', "<?php\nreturn ['name'=>'T','env'=>'testing','debug'=>true];");
        file_put_contents(
            $cfg . '/database.php',
            "<?php\nreturn ['engine'=>'sqlite','sqlite'=>['primary'=>':memory:'],'pooling'=>['enabled'=>false]];"
        );
        file_put_contents(
            $cfg . '/cache.php',
            "<?php\nreturn ['enabled'=>true,'default'=>'array','stores'=>['array'=>['driver'=>'array']]];"
        );
        file_put_contents($cfg . '/security.php', "<?php\nreturn ['csrf'=>['enabled'=>false]];");
        file_put_contents($cfg . '/session.php', "<?php\nreturn ['jwt_key'=>'test'];");

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContainer()->get(ApplicationContext::class);
    }

    private function recursiveRemoveDirectory(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursiveRemoveDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}

/**
 * Test-local controller that grants every permission so the success branch of
 * index()/destroy() is exercised without the full permission/auth stack. Only
 * `can()` is overridden — the migrated method bodies run unchanged.
 */
final class TestResourceController extends ResourceController
{
    protected function can(string $permission, string $resource = 'system', array $context = []): bool
    {
        return true;
    }
}
