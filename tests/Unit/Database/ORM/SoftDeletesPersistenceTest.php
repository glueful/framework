<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\ORM;

use Glueful\Database\ORM\Concerns\SoftDeletes;
use Glueful\Database\ORM\Model;
use Glueful\Tests\Unit\Database\ORM\Support\SqliteOrmHarness;
use PHPUnit\Framework\TestCase;

/**
 * SoftDeletes against a real SQLite table: every soft-delete method must do what its name says.
 */
final class SoftDeletesPersistenceTest extends TestCase
{
    use SqliteOrmHarness;

    protected function setUp(): void
    {
        $this->bootSqliteOrm();
        $this->connection->getSchemaBuilder()->createTable('sd_posts', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('title', 50);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    private function seed(): void
    {
        SdPost::create($this->context, ['title' => 'live']);
        $trashed = SdPost::create($this->context, ['title' => 'gone']);
        $trashed->delete();
    }

    private function rawCount(): int
    {
        return (int) $this->connection->getPDO()->query('SELECT COUNT(*) FROM sd_posts')->fetchColumn();
    }

    public function testDeleteSoftDeletesAndMarksTheInstanceTrashed(): void
    {
        $post = SdPost::create($this->context, ['title' => 'a']);

        self::assertTrue($post->delete());

        self::assertTrue($post->trashed());
        self::assertSame(1, $this->rawCount());
        self::assertSame(0, SdPost::query($this->context)->count());
    }

    public function testWithTrashedIncludesTrashedRows(): void
    {
        $this->seed();

        $titles = SdPost::query($this->context)->withTrashed()->orderBy('id')->get()
            ->map(fn (SdPost $p) => $p->title)->all();

        self::assertSame(['live', 'gone'], array_values($titles));
        self::assertSame(2, SdPost::query($this->context)->withTrashed()->count());
    }

    public function testOnlyTrashedReturnsOnlyTrashedRows(): void
    {
        $this->seed();

        $rows = SdPost::query($this->context)->onlyTrashed()->get();

        self::assertCount(1, $rows);
        self::assertSame('gone', $rows->first()?->title);
    }

    public function testDefaultQueriesStillHideTrashedRows(): void
    {
        $this->seed();

        self::assertSame(1, SdPost::query($this->context)->count());
        self::assertSame(1, SdPost::query($this->context)->withoutTrashed()->count());
    }

    public function testInstanceForceDeletePhysicallyRemovesTheRow(): void
    {
        $post = SdPost::create($this->context, ['title' => 'a']);

        self::assertTrue($post->forceDelete());

        self::assertSame(0, $this->rawCount());
        self::assertFalse($post->exists);
    }

    public function testInstanceForceDeleteRemovesAnAlreadyTrashedRow(): void
    {
        $post = SdPost::create($this->context, ['title' => 'a']);
        $post->delete();

        $trashed = SdPost::query($this->context)->withTrashed()->find($post->getKey());
        self::assertNotNull($trashed);
        self::assertTrue($trashed->forceDelete());

        self::assertSame(0, $this->rawCount());
    }

    public function testInstanceRestoreClearsDeletedAt(): void
    {
        $post = SdPost::create($this->context, ['title' => 'a']);
        $post->delete();

        $trashed = SdPost::query($this->context)->onlyTrashed()->find($post->getKey());
        self::assertNotNull($trashed);
        self::assertTrue($trashed->restore());

        self::assertFalse($trashed->trashed());
        self::assertSame(1, SdPost::query($this->context)->count());
    }

    public function testRestoreOnTheInstanceThatWasDeleted(): void
    {
        $post = SdPost::create($this->context, ['title' => 'a']);
        $post->delete();

        self::assertTrue($post->restore());

        self::assertSame(1, SdPost::query($this->context)->count());
    }

    public function testBuilderForceDeleteReallyDeletes(): void
    {
        $this->seed();

        SdPost::query($this->context)->withTrashed()->where('id', '>', 0)->forceDelete();

        self::assertSame(0, $this->rawCount());
    }

    public function testBuilderForceDeleteWithoutTrashedKeepsTrashedRows(): void
    {
        $this->seed();

        SdPost::query($this->context)->where('id', '>', 0)->forceDelete();

        // Only the live row went; the trashed one is outside the default scope.
        self::assertSame(1, $this->rawCount());
        self::assertSame(1, SdPost::query($this->context)->onlyTrashed()->count());
    }

    public function testBuilderRestoreRestoresTrashedRows(): void
    {
        $this->seed();

        SdPost::query($this->context)->restore();

        self::assertSame(2, SdPost::query($this->context)->count());
    }
}

class SdPost extends Model
{
    use SoftDeletes;

    protected string $table = 'sd_posts';

    /** @var array<string> */
    protected array $fillable = ['title'];
}
