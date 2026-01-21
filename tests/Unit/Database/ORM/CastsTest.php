<?php

declare(strict_types=1);

namespace Tests\Unit\Database\ORM;

use ArrayObject;
use BackedEnum;
use DateTimeImmutable;
use Glueful\Database\ORM\Casts\AsArrayObject;
use Glueful\Database\ORM\Casts\AsCollection;
use Glueful\Database\ORM\Casts\AsDateTime;
use Glueful\Database\ORM\Casts\AsEncryptedString;
use Glueful\Database\ORM\Casts\AsEnum;
use Glueful\Database\ORM\Casts\AsJson;
use Glueful\Database\ORM\Casts\Attribute;
use Glueful\Database\ORM\Collection;
use Glueful\Database\ORM\Model;
use PHPUnit\Framework\TestCase;

/**
 * Tests for custom cast classes
 */
class CastsTest extends TestCase
{
    // ==================== AsJson Tests ====================

    public function testAsJsonCastsToArrayOnGet(): void
    {
        $cast = new AsJson();
        $model = $this->createMockModel();

        $result = $cast->get($model, 'data', '{"name":"John","age":30}', []);

        $this->assertIsArray($result);
        $this->assertEquals('John', $result['name']);
        $this->assertEquals(30, $result['age']);
    }

    public function testAsJsonReturnsNullOnNullValue(): void
    {
        $cast = new AsJson();
        $model = $this->createMockModel();

        $result = $cast->get($model, 'data', null, []);

        $this->assertNull($result);
    }

    public function testAsJsonCastsToStringOnSet(): void
    {
        $cast = new AsJson();
        $model = $this->createMockModel();

        $result = $cast->set($model, 'data', ['name' => 'John', 'age' => 30], []);

        $this->assertIsString($result);
        $this->assertEquals('{"name":"John","age":30}', $result);
    }

    public function testAsJsonSetReturnsNullOnNullValue(): void
    {
        $cast = new AsJson();
        $model = $this->createMockModel();

        $result = $cast->set($model, 'data', null, []);

        $this->assertNull($result);
    }

    // ==================== AsArrayObject Tests ====================

    public function testAsArrayObjectCastsToArrayObject(): void
    {
        $cast = new AsArrayObject();
        $model = $this->createMockModel();

        $result = $cast->get($model, 'data', '{"name":"John"}', []);

        $this->assertInstanceOf(ArrayObject::class, $result);
        $this->assertEquals('John', $result['name']);
        /** @var ArrayObject $result */
        $this->assertEquals('John', $result->offsetGet('name')); // ARRAY_AS_PROPS flag
    }

    public function testAsArrayObjectReturnsNullOnNullValue(): void
    {
        $cast = new AsArrayObject();
        $model = $this->createMockModel();

        $result = $cast->get($model, 'data', null, []);

        $this->assertNull($result);
    }

    public function testAsArrayObjectConvertsArrayObjectToJsonOnSet(): void
    {
        $cast = new AsArrayObject();
        $model = $this->createMockModel();
        $arrayObject = new ArrayObject(['name' => 'John'], ArrayObject::ARRAY_AS_PROPS);

        $result = $cast->set($model, 'data', $arrayObject, []);

        $this->assertIsString($result);
        $this->assertEquals('{"name":"John"}', $result);
    }

    public function testAsArrayObjectConvertsArrayToJsonOnSet(): void
    {
        $cast = new AsArrayObject();
        $model = $this->createMockModel();

        $result = $cast->set($model, 'data', ['name' => 'Jane'], []);

        $this->assertEquals('{"name":"Jane"}', $result);
    }

    // ==================== AsCollection Tests ====================

    public function testAsCollectionCastsToCollection(): void
    {
        $cast = new AsCollection();
        $model = $this->createMockModel();

        $result = $cast->get($model, 'items', '[1,2,3,4,5]', []);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertEquals([1, 2, 3, 4, 5], $result->toArray());
    }

    public function testAsCollectionReturnsNullOnNullValue(): void
    {
        $cast = new AsCollection();
        $model = $this->createMockModel();

        $result = $cast->get($model, 'items', null, []);

        $this->assertNull($result);
    }

    public function testAsCollectionConvertsCollectionToJsonOnSet(): void
    {
        $cast = new AsCollection();
        $model = $this->createMockModel();
        $collection = new Collection([1, 2, 3]);

        $result = $cast->set($model, 'items', $collection, []);

        $this->assertEquals('[1,2,3]', $result);
    }

    // ==================== AsDateTime Tests ====================

    public function testAsDateTimeCastsToDateTimeImmutable(): void
    {
        $cast = new AsDateTime();
        $model = $this->createMockModel();

        $result = $cast->get($model, 'created_at', '2024-01-15 10:30:00', []);

        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertEquals('2024', $result->format('Y'));
        $this->assertEquals('01', $result->format('m'));
        $this->assertEquals('15', $result->format('d'));
    }

    public function testAsDateTimeReturnsNullOnNullValue(): void
    {
        $cast = new AsDateTime();
        $model = $this->createMockModel();

        $result = $cast->get($model, 'created_at', null, []);

        $this->assertNull($result);
    }

    public function testAsDateTimeReturnsExistingDateTimeImmutable(): void
    {
        $cast = new AsDateTime();
        $model = $this->createMockModel();
        $datetime = new DateTimeImmutable('2024-06-01');

        $result = $cast->get($model, 'created_at', $datetime, []);

        $this->assertSame($datetime, $result);
    }

    public function testAsDateTimeConvertsToStringOnSet(): void
    {
        $cast = new AsDateTime();
        $model = $this->createMockModel();
        $datetime = new DateTimeImmutable('2024-01-15 10:30:45');

        $result = $cast->set($model, 'created_at', $datetime, []);

        $this->assertEquals('2024-01-15 10:30:45', $result);
    }

    public function testAsDateTimeWithCustomFormat(): void
    {
        $cast = AsDateTime::format('Y-m-d');
        $model = $this->createMockModel();
        $datetime = new DateTimeImmutable('2024-01-15 10:30:45');

        $result = $cast->set($model, 'date', $datetime, []);

        $this->assertEquals('2024-01-15', $result);
    }

    // ==================== AsEncryptedString Tests ====================

    public function testAsEncryptedStringEncryptsAndDecrypts(): void
    {
        // Set encryption key
        AsEncryptedString::setKey('test-encryption-key-32-chars!!');

        $cast = new AsEncryptedString();
        $model = $this->createMockModel();
        $plaintext = 'secret-password-123';

        // Encrypt
        $encrypted = $cast->set($model, 'password', $plaintext, []);

        $this->assertIsString($encrypted);
        $this->assertNotEquals($plaintext, $encrypted);

        // Decrypt
        $decrypted = $cast->get($model, 'password', $encrypted, []);

        $this->assertEquals($plaintext, $decrypted);
    }

    public function testAsEncryptedStringReturnsNullForNullValues(): void
    {
        AsEncryptedString::setKey('test-encryption-key-32-chars!!');

        $cast = new AsEncryptedString();
        $model = $this->createMockModel();

        $this->assertNull($cast->get($model, 'password', null, []));
        $this->assertNull($cast->set($model, 'password', null, []));
    }

    public function testAsEncryptedStringReturnsNullForInvalidData(): void
    {
        AsEncryptedString::setKey('test-encryption-key-32-chars!!');

        $cast = new AsEncryptedString();
        $model = $this->createMockModel();

        // Invalid base64
        $result = $cast->get($model, 'password', 'not-valid-base64!!!', []);
        $this->assertNull($result);
    }

    // ==================== AsEnum Tests ====================

    public function testAsEnumCastsToEnumOnGet(): void
    {
        $cast = new AsEnum(TestStatus::class);
        $model = $this->createMockModel();

        $result = $cast->get($model, 'status', 'active', []);

        $this->assertInstanceOf(TestStatus::class, $result);
        $this->assertEquals(TestStatus::Active, $result);
    }

    public function testAsEnumReturnsNullOnNullValue(): void
    {
        $cast = new AsEnum(TestStatus::class);
        $model = $this->createMockModel();

        $result = $cast->get($model, 'status', null, []);

        $this->assertNull($result);
    }

    public function testAsEnumReturnsNullOnInvalidValue(): void
    {
        $cast = new AsEnum(TestStatus::class);
        $model = $this->createMockModel();

        $result = $cast->get($model, 'status', 'invalid_status', []);

        $this->assertNull($result);
    }

    public function testAsEnumConvertsEnumToValueOnSet(): void
    {
        $cast = new AsEnum(TestStatus::class);
        $model = $this->createMockModel();

        $result = $cast->set($model, 'status', TestStatus::Inactive, []);

        $this->assertEquals('inactive', $result);
    }

    public function testAsEnumAcceptsBackingValueOnSet(): void
    {
        $cast = new AsEnum(TestStatus::class);
        $model = $this->createMockModel();

        $result = $cast->set($model, 'status', 'pending', []);

        $this->assertEquals('pending', $result);
    }

    public function testAsEnumOfStaticConstructor(): void
    {
        $cast = AsEnum::of(TestStatus::class);

        $this->assertInstanceOf(AsEnum::class, $cast);
    }

    // ==================== Attribute Class Tests ====================

    public function testAttributeMakeCreatesInstance(): void
    {
        $attribute = Attribute::make(
            get: fn ($value) => strtoupper($value),
            set: fn ($value) => strtolower($value)
        );

        $this->assertInstanceOf(Attribute::class, $attribute);
        $this->assertNotNull($attribute->get);
        $this->assertNotNull($attribute->set);
    }

    public function testAttributeGetCreatesGetterOnly(): void
    {
        $attribute = Attribute::get(fn ($value) => strtoupper($value));

        $this->assertNotNull($attribute->get);
        $this->assertNull($attribute->set);
    }

    public function testAttributeSetCreatesSetterOnly(): void
    {
        $attribute = Attribute::set(fn ($value) => strtolower($value));

        $this->assertNull($attribute->get);
        $this->assertNotNull($attribute->set);
    }

    public function testAttributeShouldCacheEnablesCaching(): void
    {
        $attribute = Attribute::make(
            get: fn ($value) => $value
        )->shouldCache();

        $this->assertTrue($attribute->withCaching);
    }

    public function testAttributeGetterIsCalled(): void
    {
        $attribute = Attribute::make(
            get: fn ($value) => strtoupper($value ?? '')
        );

        $result = ($attribute->get)('hello', []);

        $this->assertEquals('HELLO', $result);
    }

    public function testAttributeSetterIsCalled(): void
    {
        $attribute = Attribute::make(
            set: fn ($value) => strtolower($value)
        );

        $result = ($attribute->set)('HELLO', []);

        $this->assertEquals('hello', $result);
    }

    // ==================== Helper Methods ====================

    private function createMockModel(): Model
    {
        return new class extends Model {
            protected string $table = 'test_models';
        };
    }
}

/**
 * Test enum for AsEnum tests
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
enum TestStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Pending = 'pending';
}
