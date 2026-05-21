<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database\ORM;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\ORM\Model;
use Glueful\Database\ORM\Exceptions\LazyLoadingViolationException;
use Glueful\Framework;
use PHPUnit\Framework\TestCase;
use Glueful\Tests\Support\Traits\ResetsLazyLoading;

class IntUser extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['name'];

    public function posts(): \Glueful\Database\ORM\Relations\HasMany
    {
        return $this->hasMany(IntPost::class, 'user_id');
    }
}

class IntPost extends Model
{
    protected string $table = 'posts';
    protected array $fillable = ['user_id', 'title'];

    public function comments(): \Glueful\Database\ORM\Relations\HasMany
    {
        return $this->hasMany(IntComment::class, 'post_id');
    }
}

class IntComment extends Model
{
    protected string $table = 'comments';
    protected array $fillable = ['post_id', 'text'];
}

class LegacyIntUser extends IntUser
{
    protected ?string $instanceLazyLoadingMode = 'off';
}

class LazyLoadingDetectionTest extends TestCase
{
    use ResetsLazyLoading;

    private string $appPath;
    private Application $app;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFramework();
        $this->setUpSchema();
        $this->seed();
    }

    protected function tearDown(): void
    {
        // The ResetsLazyLoading trait defines tearDown() which clears N+1
        // detector static state. This class overrides tearDown(), so we must
        // explicitly call the reset here — PHP trait method resolution lets
        // the class's method shadow the trait's.
        Model::resetLazyLoadingState();

        if (isset($this->appPath) && is_dir($this->appPath)) {
            $this->recursiveRemoveDirectory($this->appPath);
        }
        parent::tearDown();
    }

    public function testCollectionThenLazyAccessTriggersStrict(): void
    {
        Model::preventLazyLoading('strict');

        $users = IntUser::query($this->context)->get();
        $this->assertGreaterThan(1, count($users));

        $this->expectException(LazyLoadingViolationException::class);
        $users[0]->posts;
    }

    public function testFindThenAccessDoesNotTrigger(): void
    {
        Model::preventLazyLoading('strict');

        $user = IntUser::query($this->context)->find(1);
        $posts = $user->posts;  // single-row hydration — no detection
        $this->assertNotNull($posts);
    }

    public function testEagerLoadedRelationDoesNotTrigger(): void
    {
        Model::preventLazyLoading('strict');

        $users = IntUser::with($this->context, 'posts')->get();
        $posts = $users[0]->posts;  // already loaded — no lazy load
        $this->assertNotNull($posts);
    }

    public function testNestedCollectionLoadStillTriggers(): void
    {
        Model::preventLazyLoading('strict');

        $users = IntUser::with($this->context, 'posts')->get();
        $firstPost = $users[0]->posts[0] ?? null;
        $this->assertNotNull($firstPost);

        $this->expectException(LazyLoadingViolationException::class);
        $firstPost->comments;  // comments NOT eager-loaded
    }

    public function testPerModelOptOutSkipsDetection(): void
    {
        Model::preventLazyLoading('strict');

        $users = LegacyIntUser::query($this->context)->get();
        $posts = $users[0]->posts;  // opted out, no detection
        $this->assertNotNull($posts);
    }

    public function testWarnModeLogsButDoesNotThrow(): void
    {
        Model::preventLazyLoading('warn');

        $tmp = tempnam(sys_get_temp_dir(), 'glueful-n1-');
        $prev = ini_set('error_log', $tmp);
        try {
            $users = IntUser::query($this->context)->get();
            $users[0]->posts;

            $this->assertStringContainsString('[GLUEFUL-N+1]', file_get_contents($tmp));
        } finally {
            ini_set('error_log', $prev);
            @unlink($tmp);
        }
    }

    public function testHydratedCollectionIsTaggedAsLoadedFromCollection(): void
    {
        Model::preventLazyLoading('warn');

        $users = IntUser::query($this->context)->get();
        $this->assertGreaterThan(1, count($users));
        $this->assertTrue($users[0]->wasLoadedFromCollection());
    }

    public function testCoexistsWithDevelopmentQueryMonitor(): void
    {
        // Both detectors should be able to fire on the same query without
        // interfering with each other.
        \Glueful\Database\DevelopmentQueryMonitor::reset();
        Model::preventLazyLoading('warn');

        $tmp = tempnam(sys_get_temp_dir(), 'glueful-n1-');
        $prev = ini_set('error_log', $tmp);
        try {
            $users = IntUser::query($this->context)->get();
            // Lazy-load — triggers our detector and potentially the SQL one
            $users[0]->posts;

            $log = file_get_contents($tmp);
            $this->assertStringContainsString('[GLUEFUL-N+1]', $log);
            // The existing monitor uses different prefixes; we just verify
            // no fatal interaction by getting here without an exception.
        } finally {
            ini_set('error_log', $prev);
            @unlink($tmp);
        }
    }

    private function bootFramework(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-n1-' . uniqid();
        $configPath = $this->appPath . '/config';
        mkdir($configPath, 0755, true);

        file_put_contents(
            $configPath . '/app.php',
            "<?php\nreturn ['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true];\n"
        );
        file_put_contents(
            $configPath . '/database.php',
            "<?php\nreturn ["
            . "'engine' => 'sqlite', "
            . "'sqlite' => ['primary' => ':memory:'], "
            . "'pooling' => ['enabled' => false], "
            . "'orm' => ['lazy_loading_mode' => 'off']"
            . "];\n"
        );
        file_put_contents(
            $configPath . '/cache.php',
            "<?php\nreturn ['enabled' => true, 'default' => 'array', "
            . "'stores' => ['array' => ['driver' => 'array']]];\n"
        );
        file_put_contents(
            $configPath . '/security.php',
            "<?php\nreturn ['csrf' => ['enabled' => false]];\n"
        );
        file_put_contents(
            $configPath . '/session.php',
            "<?php\nreturn ['jwt_key' => 'test'];\n"
        );

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContainer()->get(ApplicationContext::class);
    }

    private function setUpSchema(): void
    {
        // CoreProvider registers the Connection under the service id 'database',
        // not under the Glueful\Database\Connection class name.
        $pdo = $this->app->getContainer()->get('database')->getPDO();
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT)');
        $pdo->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY, post_id INTEGER, text TEXT)');
    }

    private function seed(): void
    {
        $pdo = $this->app->getContainer()->get('database')->getPDO();
        $pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Alice'), (2, 'Bob')");
        $pdo->exec("INSERT INTO posts (id, user_id, title) VALUES (1, 1, 'P1'), (2, 1, 'P2'), (3, 2, 'P3')");
        $pdo->exec("INSERT INTO comments (id, post_id, text) VALUES (1, 1, 'C1'), (2, 1, 'C2')");
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
