<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\ORM;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the HasAttributes trait
 */
class HasAttributesTest extends TestCase
{
    private TestModel $model;

    protected function setUp(): void
    {
        $this->model = new TestModel();
    }

    public function testSetAndGetAttribute(): void
    {
        $this->model->name = 'John';

        $this->assertSame('John', $this->model->name);
    }

    public function testGetAttributeReturnsNullForEmpty(): void
    {
        $this->assertNull($this->model->getAttribute(''));
    }

    public function testFillSetsAttributes(): void
    {
        $this->model->fill(['name' => 'John', 'email' => 'john@example.com']);

        $this->assertSame('John', $this->model->name);
        $this->assertSame('john@example.com', $this->model->email);
    }

    public function testGetAttributesReturnsAll(): void
    {
        $this->model->name = 'John';
        $this->model->email = 'john@example.com';

        $attributes = $this->model->getAttributes();

        $this->assertSame('John', $attributes['name']);
        $this->assertSame('john@example.com', $attributes['email']);
    }

    public function testSetRawAttributesSetsWithoutProtection(): void
    {
        $this->model->setRawAttributes(['name' => 'John', 'id' => 1]);

        $this->assertSame('John', $this->model->name);
        $this->assertSame(1, $this->model->id);
    }

    public function testSyncOriginal(): void
    {
        $this->model->setRawAttributes(['name' => 'John']);
        $this->model->syncOriginal();

        $this->assertSame('John', $this->model->getOriginal('name'));
    }

    public function testIsDirtyDetectsChanges(): void
    {
        $this->model->setRawAttributes(['name' => 'John']);
        $this->model->syncOriginal();

        $this->assertFalse($this->model->isDirty());

        $this->model->name = 'Jane';

        $this->assertTrue($this->model->isDirty());
        $this->assertTrue($this->model->isDirty('name'));
        $this->assertFalse($this->model->isDirty('email'));
    }

    public function testIsCleanIsInverseOfIsDirty(): void
    {
        $this->model->setRawAttributes(['name' => 'John']);
        $this->model->syncOriginal();

        $this->assertTrue($this->model->isClean());

        $this->model->name = 'Jane';

        $this->assertFalse($this->model->isClean());
    }

    public function testGetDirtyReturnsChangedAttributes(): void
    {
        $this->model->setRawAttributes(['name' => 'John']);
        $this->model->syncOriginal();

        $this->model->name = 'Jane';
        $this->model->email = 'jane@example.com';

        $dirty = $this->model->getDirty();

        $this->assertArrayHasKey('name', $dirty);
        $this->assertArrayHasKey('email', $dirty);
        $this->assertSame('Jane', $dirty['name']);
    }

    public function testCastingInteger(): void
    {
        $model = new CastingModel();
        $model->setRawAttributes(['count' => '42']);

        $this->assertSame(42, $model->count);
        $this->assertIsInt($model->count);
    }

    public function testCastingBoolean(): void
    {
        $model = new CastingModel();
        $model->setRawAttributes(['active' => 1]);

        $this->assertTrue($model->active);
        $this->assertIsBool($model->active);
    }

    public function testCastingFloat(): void
    {
        $model = new CastingModel();
        $model->setRawAttributes(['price' => '19.99']);

        $this->assertSame(19.99, $model->price);
        $this->assertIsFloat($model->price);
    }

    public function testCastingArray(): void
    {
        $model = new CastingModel();
        $model->setRawAttributes(['meta' => '{"key":"value"}']);

        $this->assertIsArray($model->meta);
        $this->assertSame(['key' => 'value'], $model->meta);
    }

    public function testCastingDatetime(): void
    {
        $model = new CastingModel();
        $model->setRawAttributes(['created' => '2024-01-15 10:30:00']);

        $this->assertInstanceOf(\DateTimeInterface::class, $model->created);
    }

    public function testIsFillable(): void
    {
        $this->assertTrue($this->model->isFillable('name'));
        $this->assertTrue($this->model->isFillable('email'));
        $this->assertFalse($this->model->isFillable('password')); // not in fillable
    }

    public function testHiddenAttributesExcludedFromArray(): void
    {
        $model = new HiddenModel();
        $model->setRawAttributes(['name' => 'John', 'password' => 'secret']);

        $array = $model->attributesToArray();

        $this->assertArrayHasKey('name', $array);
        $this->assertArrayNotHasKey('password', $array);
    }

    public function testVisibleAttributesOnly(): void
    {
        $model = new VisibleModel();
        $model->setRawAttributes(['name' => 'John', 'email' => 'john@example.com', 'password' => 'secret']);

        $array = $model->attributesToArray();

        $this->assertArrayHasKey('name', $array);
        $this->assertArrayNotHasKey('email', $array);
        $this->assertArrayNotHasKey('password', $array);
    }

    public function testMakeVisible(): void
    {
        $model = new HiddenModel();
        $model->setRawAttributes(['name' => 'John', 'password' => 'secret']);

        $model->makeVisible('password');

        $array = $model->attributesToArray();
        $this->assertArrayHasKey('password', $array);
    }

    public function testMakeHidden(): void
    {
        $this->model->setRawAttributes(['name' => 'John', 'email' => 'john@example.com']);
        $this->model->makeHidden('email');

        $array = $this->model->attributesToArray();

        $this->assertArrayHasKey('name', $array);
        $this->assertArrayNotHasKey('email', $array);
    }

    public function testIsset(): void
    {
        $this->model->name = 'John';

        $this->assertTrue(isset($this->model->name));
        $this->assertFalse(isset($this->model->email));
    }

    public function testUnset(): void
    {
        $this->model->name = 'John';

        unset($this->model->name);

        $this->assertFalse(isset($this->model->name));
    }
}

/**
 * Test model for HasAttributes tests
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class TestModel
{
    use \Glueful\Database\ORM\Concerns\HasAttributes;

    public function __construct()
    {
        $this->fillable = ['name', 'email'];
        $this->guarded = [];
    }
}

/**
 * Test model with casting
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class CastingModel
{
    use \Glueful\Database\ORM\Concerns\HasAttributes;

    public function __construct()
    {
        $this->casts = [
            'count' => 'integer',
            'active' => 'boolean',
            'price' => 'float',
            'meta' => 'array',
            'created' => 'datetime',
        ];
        $this->fillable = ['count', 'active', 'price', 'meta', 'created'];
        $this->guarded = [];
    }
}

/**
 * Test model with hidden attributes
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class HiddenModel
{
    use \Glueful\Database\ORM\Concerns\HasAttributes;

    public function __construct()
    {
        $this->hidden = ['password'];
        $this->fillable = ['name', 'password'];
        $this->guarded = [];
    }
}

/**
 * Test model with visible attributes
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class VisibleModel
{
    use \Glueful\Database\ORM\Concerns\HasAttributes;

    public function __construct()
    {
        $this->visible = ['name'];
        $this->fillable = ['name', 'email', 'password'];
        $this->guarded = [];
    }
}
