<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\ORM;

use Glueful\Database\ORM\Model;
use Glueful\Tests\Unit\Database\ORM\Support\SqliteOrmHarness;
use PHPUnit\Framework\TestCase;

/**
 * Default `$timestamps = true` must persist on a model with no datetime casts: the timestamps
 * (and any DateTimeInterface attribute) are formatted to the model's date format at the
 * persistence boundary instead of binding a DateTimeImmutable object to PDO.
 */
final class ModelTimestampsPersistenceTest extends TestCase
{
    use SqliteOrmHarness;

    private const DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    protected function setUp(): void
    {
        $this->bootSqliteOrm();
        $this->connection->getSchemaBuilder()->createTable('stamped_items', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('name', 50);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function testCreateWithoutDatetimeCastsStoresFormattedTimestamps(): void
    {
        $item = StampedItem::create($this->context, ['name' => 'first']);

        self::assertTrue($item->exists);
        $row = $this->connection->table('stamped_items')->where('id', $item->getKey())->first();
        self::assertIsArray($row);
        self::assertMatchesRegularExpression(self::DATETIME_PATTERN, (string) $row['created_at']);
        self::assertMatchesRegularExpression(self::DATETIME_PATTERN, (string) $row['updated_at']);
    }

    public function testUpdateWithoutDatetimeCastsStoresFormattedUpdatedAt(): void
    {
        $item = StampedItem::create($this->context, ['name' => 'first']);
        $item->name = 'renamed';

        self::assertTrue($item->save());

        $row = $this->connection->table('stamped_items')->where('id', $item->getKey())->first();
        self::assertIsArray($row);
        self::assertSame('renamed', $row['name']);
        self::assertMatchesRegularExpression(self::DATETIME_PATTERN, (string) $row['updated_at']);
    }

    public function testAUserSetDateTimeAttributeWithoutACastIsFormattedOnSave(): void
    {
        $item = new StampedItem(['name' => 'dated'], $this->context);
        $item->published_at = new \DateTimeImmutable('2024-05-06 07:08:09');

        self::assertTrue($item->save());

        $row = $this->connection->table('stamped_items')->where('id', $item->getKey())->first();
        self::assertIsArray($row);
        self::assertSame('2024-05-06 07:08:09', $row['published_at']);
    }

    public function testDatetimeCastStillReadsBackAsDateTimeImmutable(): void
    {
        $item = CastStampedItem::create($this->context, ['name' => 'cast']);

        $fresh = CastStampedItem::find($this->context, $item->getKey());

        self::assertNotNull($fresh);
        self::assertInstanceOf(\DateTimeImmutable::class, $fresh->created_at);
        self::assertInstanceOf(\DateTimeImmutable::class, $fresh->updated_at);
        self::assertInstanceOf(\DateTimeImmutable::class, $item->created_at);
    }
}

class StampedItem extends Model
{
    protected string $table = 'stamped_items';

    /** @var array<string> */
    protected array $fillable = ['name'];
}

class CastStampedItem extends Model
{
    protected string $table = 'stamped_items';

    /** @var array<string> */
    protected array $fillable = ['name'];

    /** @var array<string, string> */
    protected array $casts = ['created_at' => 'datetime', 'updated_at' => 'datetime'];
}
