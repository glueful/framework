<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database\ORM;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\ORM\Model;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;

class IdWidget extends Model
{
    protected string $table = 'id_widgets';
    protected array $fillable = ['name'];
    public bool $timestamps = false;
}

/**
 * Model::performInsert() took insert()'s return value as the new primary key, but insert()
 * returns the affected-row count. Every auto-increment model created through the ORM came back
 * with id 1, so anything keyed on it afterwards (a queued job, a child row, an update) pointed
 * at the first row in the table.
 */
final class ModelCreateIdTest extends TestCase
{
    private string $appPath;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        RouteManifest::reset();
        $this->appPath = sys_get_temp_dir() . '/glueful-model-id-' . uniqid('', true);
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        $files = [
            'app' => "['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true, "
                . "'key' => 'test-key']",
            'database' => "['engine' => 'sqlite', 'sqlite' => ['primary' => '" . $this->appPath . "/m.sqlite'], "
                . "'pooling' => ['enabled' => false]]",
            'cache' => "['enabled' => true, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]",
            'security' => "['csrf' => ['enabled' => false]]",
            'session' => "['jwt_key' => 'test']",
        ];
        foreach ($files as $name => $body) {
            file_put_contents("{$cfg}/{$name}.php", "<?php\nreturn {$body};\n");
        }
        $this->context = Framework::create($this->appPath)->boot(allowReboot: true)->getContext();
        Connection::fromContext($this->context)->getPDO()
            ->exec('CREATE TABLE id_widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->appPath));
    }

    public function testEachCreatedModelCarriesItsOwnGeneratedId(): void
    {
        $first = IdWidget::create($this->context, ['name' => 'first']);
        $second = IdWidget::create($this->context, ['name' => 'second']);
        $third = IdWidget::create($this->context, ['name' => 'third']);

        self::assertSame([1, 2, 3], [(int) $first->id, (int) $second->id, (int) $third->id]);
        self::assertSame('second', IdWidget::query($this->context)->find($second->id)?->name);
    }
}
