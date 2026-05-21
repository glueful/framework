<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database\ORM;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\ORM\Model;
use Glueful\Framework;
use PHPUnit\Framework\TestCase;

class ExplainUser extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['name'];
}

class QueryExplainTest extends TestCase
{
    private string $appPath;
    private Application $app;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFramework();
        $this->setUpSchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->appPath) && is_dir($this->appPath)) {
            $this->recursiveRemoveDirectory($this->appPath);
        }
        parent::tearDown();
    }

    public function testOrmBuilderExplainReturnsRowsForSelect(): void
    {
        $plan = ExplainUser::query($this->context)
            ->where('name', '=', 'Alice')
            ->explain();

        $this->assertIsArray($plan);
        $this->assertGreaterThan(0, count($plan), 'EXPLAIN should return at least one row');
        $this->assertIsArray($plan[0], 'Each row should be an associative array');
    }

    public function testExplainOnSqliteUsesQueryPlanColumns(): void
    {
        // SQLite EXPLAIN QUERY PLAN returns rows with these column names.
        // This test pins the driver-aware behavior — if explain() ever
        // regresses to plain `EXPLAIN` on SQLite, the columns would change.
        $plan = ExplainUser::query($this->context)->explain();

        $this->assertNotEmpty($plan);
        $row = $plan[0];
        $this->assertArrayHasKey('detail', $row, 'SQLite EXPLAIN QUERY PLAN should include a "detail" column');
    }

    public function testQueryBuilderExplainAlsoWorks(): void
    {
        // The lower-level QueryBuilder::explain() is what ORM Builder delegates to.
        $plan = $this->app->getContainer()->get('database')
            ->table('users')
            ->where('name', '=', 'Alice')
            ->explain();

        $this->assertIsArray($plan);
        $this->assertNotEmpty($plan);
    }

    private function bootFramework(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-explain-' . uniqid();
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
            . "'pooling' => ['enabled' => false]"
            . "];\n"
        );
        file_put_contents(
            $configPath . '/cache.php',
            "<?php\nreturn ['enabled' => true, 'default' => 'array', "
            . "'stores' => ['array' => ['driver' => 'array']]];\n"
        );
        file_put_contents($configPath . '/security.php', "<?php\nreturn ['csrf' => ['enabled' => false]];\n");
        file_put_contents($configPath . '/session.php', "<?php\nreturn ['jwt_key' => 'test'];\n");

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContainer()->get(ApplicationContext::class);
    }

    private function setUpSchema(): void
    {
        $pdo = $this->app->getContainer()->get('database')->getPDO();
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Alice'), (2, 'Bob')");
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
