<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\ORM;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Factory\FakerBridge;
use Glueful\Database\ORM\Model;
use Glueful\Tests\Unit\Database\ORM\Support\SqliteOrmHarness;
use PHPUnit\Framework\TestCase;

/**
 * Model::factory() built its factory without a context, so the documented
 * `User::factory()->create()` always threw "ApplicationContext is required to create models".
 * A factory now uses the app's default model context (set at boot), an explicit one passed to
 * factory(), or one given with withContext().
 */
final class FactoryContextTest extends TestCase
{
    use SqliteOrmHarness;

    private ?ApplicationContext $previousDefault;

    public static function setUpBeforeClass(): void
    {
        // Faker is an optional dependency the framework doesn't install; definition() doesn't use it.
        if (!class_exists('Faker\\Factory')) {
            eval('namespace Faker; class Factory { public static function create(): object { return new \stdClass(); } }');
        }
        if (!class_exists('App\\Models\\FactoryContextProbe', false)) {
            eval(<<<'PHP'
                namespace App\Models;
                class FactoryContextProbe extends \Glueful\Database\ORM\Model {
                    use \Glueful\Database\ORM\Concerns\HasFactory;
                    protected string $table = 'factory_probes';
                    protected array $fillable = ['title'];
                    public bool $timestamps = false;
                }
                PHP);
            eval(<<<'PHP'
                namespace App\Database\Factories;
                class FactoryContextProbeFactory extends \Glueful\Database\Factory\Factory {
                    protected string $model = \App\Models\FactoryContextProbe::class;
                    public function definition(): array { return ['title' => 'probe']; }
                }
                PHP);
        }
    }

    protected function setUp(): void
    {
        $this->bootSqliteOrm();
        $this->connection->getPDO()->exec(
            'CREATE TABLE factory_probes (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)'
        );

        $default = new \ReflectionProperty(Model::class, 'defaultContext');
        $this->previousDefault = $default->getValue();
        $default->setValue(null, null);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Model::class, 'defaultContext'))->setValue(null, $this->previousDefault);
        FakerBridge::reset();
    }

    private function rows(): int
    {
        return (int) $this->connection->getPDO()->query('SELECT COUNT(*) FROM factory_probes')->fetchColumn();
    }

    public function testFactoryCreateUsesTheAppsDefaultModelContext(): void
    {
        Model::setDefaultContext($this->context);

        $probe = \App\Models\FactoryContextProbe::factory(2)->create();

        self::assertSame(2, $this->rows());
        self::assertSame($this->context, $probe->getContext());
    }

    public function testFactoryAcceptsAnExplicitContext(): void
    {
        \App\Models\FactoryContextProbe::factory(1, $this->context)->create();

        self::assertSame(1, $this->rows());
    }

    public function testWithContextSetsTheContext(): void
    {
        \App\Models\FactoryContextProbe::factory()->withContext($this->context)->create();

        self::assertSame(1, $this->rows());
    }

    public function testMadeModelsCarryTheContextSoTheyCanBeSavedLater(): void
    {
        Model::setDefaultContext($this->context);

        $probe = \App\Models\FactoryContextProbe::factory()->make();
        $probe->save();

        self::assertSame(1, $this->rows());
    }

    public function testWithoutAnyContextTheErrorSaysHowToProvideOne(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('withContext');

        \App\Models\FactoryContextProbe::factory()->create();
    }
}
