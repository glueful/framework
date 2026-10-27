<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\ORM;

use Glueful\Database\ORM\Concerns\SoftDeletes;
use Glueful\Database\ORM\Model;
use Glueful\Tests\Unit\Database\ORM\Support\SqliteOrmHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every documented model event reaches its registered callbacks, and a false return from a
 * cancellable ("-ing") callback cancels the write.
 */
final class ModelEventCallbacksTest extends TestCase
{
    use SqliteOrmHarness;

    /** @var list<string> */
    public static array $fired = [];

    protected function setUp(): void
    {
        $this->bootSqliteOrm();
        $this->connection->getSchemaBuilder()->createTable('ev_posts', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('title', 50);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        self::$fired = [];
        $callbacks = new \ReflectionProperty(Model::class, 'modelEventCallbacks');
        $callbacks->setValue(null, []);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Model::class, 'modelEventCallbacks'))->setValue(null, []);
    }

    private function record(string ...$events): void
    {
        foreach ($events as $event) {
            $register = $event === 'trashed' ? 'onTrashed' : $event;
            EvPost::$register(function () use ($event): void {
                self::$fired[] = $event;
            });
        }
    }

    private function rowCount(): int
    {
        return (int) $this->connection->getPDO()->query('SELECT COUNT(*) FROM ev_posts')->fetchColumn();
    }

    public function testSaveAndUpdateEventsFireInOrder(): void
    {
        $this->record('saving', 'creating', 'created', 'saved', 'updating', 'updated');

        $post = EvPost::create($this->context, ['title' => 'a']);
        $post->title = 'b';
        $post->save();

        self::assertSame(
            ['saving', 'creating', 'created', 'saved', 'saving', 'updating', 'updated', 'saved'],
            self::$fired
        );
    }

    public function testSoftDeleteRestoreAndForceDeleteEventsFire(): void
    {
        $this->record('deleting', 'deleted', 'trashed', 'restoring', 'restored', 'forceDeleting', 'forceDeleted');

        $post = EvPost::create($this->context, ['title' => 'a']);
        $post->delete();
        $post->restore();
        $post->forceDelete();

        self::assertSame(
            [
                'deleting', 'trashed', 'deleted',
                'restoring', 'restored',
                'forceDeleting', 'deleting', 'deleted', 'forceDeleted',
            ],
            self::$fired
        );
    }

    /** @return array<string, array{string}> */
    public static function cancellingInsertEvents(): array
    {
        return ['saving' => ['saving'], 'creating' => ['creating']];
    }

    #[DataProvider('cancellingInsertEvents')]
    public function testFalseFromAnInsertCallbackCancelsTheInsert(string $event): void
    {
        EvPost::$event(fn () => false);

        $post = new EvPost(['title' => 'a'], $this->context);

        self::assertFalse($post->save());
        self::assertFalse($post->exists);
        self::assertSame(0, $this->rowCount());
    }

    public function testFalseFromUpdatingCancelsTheUpdate(): void
    {
        $post = EvPost::create($this->context, ['title' => 'a']);
        EvPost::updating(fn () => false);

        $post->title = 'b';

        self::assertFalse($post->save());
        $row = $this->connection->table('ev_posts')->where('id', $post->getKey())->first();
        self::assertIsArray($row);
        self::assertSame('a', $row['title']);
    }

    public function testFalseFromDeletingCancelsTheDelete(): void
    {
        $post = EvPost::create($this->context, ['title' => 'a']);
        EvPost::deleting(fn () => false);

        self::assertFalse($post->delete());
        self::assertFalse($post->trashed());
        self::assertSame(1, EvPost::query($this->context)->count());
    }

    public function testFalseFromRestoringCancelsTheRestore(): void
    {
        $post = EvPost::create($this->context, ['title' => 'a']);
        $post->delete();
        EvPost::restoring(fn () => false);

        self::assertFalse($post->restore());
        self::assertSame(0, EvPost::query($this->context)->count());
    }

    public function testFalseFromForceDeletingCancelsTheForceDelete(): void
    {
        $post = EvPost::create($this->context, ['title' => 'a']);
        EvPost::forceDeleting(fn () => false);

        self::assertFalse($post->forceDelete());
        self::assertSame(1, $this->rowCount());
    }

    public function testNonFalseReturnsDoNotCancel(): void
    {
        EvPost::creating(fn () => null);
        EvPost::saving(fn () => true);

        $post = EvPost::create($this->context, ['title' => 'a']);

        self::assertTrue($post->exists);
        self::assertSame(1, $this->rowCount());
    }
}

class EvPost extends Model
{
    use SoftDeletes;

    protected string $table = 'ev_posts';

    /** @var array<string> */
    protected array $fillable = ['title'];
}
