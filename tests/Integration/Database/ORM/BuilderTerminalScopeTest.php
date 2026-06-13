<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database\ORM;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\ORM\Builder;
use Glueful\Database\ORM\Contracts\Scope;
use Glueful\Database\ORM\Model;
use Glueful\Framework;
use PHPUnit\Framework\TestCase;

final class NameAliceScope implements Scope
{
    public function apply(Builder $builder, object $model): void
    {
        $builder->where('name', '=', 'Alice');
    }
}

final class ScopedTerminalUser extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['name'];

    protected static function boot(): void
    {
        static::addGlobalScope(new NameAliceScope());
    }
}

final class BuilderTerminalScopeTest extends TestCase
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

    public function testPaginateAppliesGlobalScopesBeforeProxyingToQueryBuilder(): void
    {
        $page = ScopedTerminalUser::query($this->context)->paginate(1, 10);

        self::assertSame(1, $page['total']);
        self::assertCount(1, $page['data']);
        self::assertSame('Alice', $page['data'][0]['name']);
    }

    public function testMaxAppliesGlobalScopesBeforeProxyingToQueryBuilder(): void
    {
        self::assertSame(1, ScopedTerminalUser::query($this->context)->max('id'));
    }

    private function bootFramework(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-builder-terminal-' . uniqid();
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
