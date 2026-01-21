<?php

declare(strict_types=1);

namespace Tests\Unit\Database\ORM;

use Glueful\Database\ORM\Contracts\Scope;
use Glueful\Database\ORM\Model;
use Glueful\Database\ORM\Builder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Model class functionality
 */
class ModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    // ==================== Basic Model Tests ====================

    public function testModelCanBeInstantiated(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    public function testModelCanSetAndGetAttributes(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    public function testGetTableReturnsTableName(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    public function testGetKeyNameReturnsPrimaryKey(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    public function testGetKeyReturnsKeyValue(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    public function testExistsIsFalseByDefault(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    // ==================== Fill and Mass Assignment Tests ====================

    public function testFillSetsOnlyFillableAttributes(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    public function testForceFillBypassesMassAssignmentProtection(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    // ==================== toArray and JSON Tests ====================

    public function testToArrayReturnsAttributesAndRelations(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    public function testToJsonReturnsJsonString(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    public function testToJsonWithOptions(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    // ==================== Hidden and Visible Tests ====================

    public function testHiddenAttributesAreExcludedFromArray(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    public function testMakeVisibleOverridesHidden(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    public function testMakeHiddenHidesAttribute(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    // ==================== Global Scopes Tests ====================

    public function testAddGlobalScopeRegistersScope(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    public function testHasGlobalScopeReturnsTrueForRegisteredScope(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    public function testHasGlobalScopeReturnsFalseForUnregisteredScope(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    // ==================== New Instance Tests ====================

    public function testNewInstanceCreatesNewModel(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    public function testNewFromBuilderCreatesExistingModel(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    // ==================== Fresh Timestamp Tests ====================

    public function testFreshTimestampReturnsDateTime(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    public function testFreshTimestampStringReturnsFormattedString(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    // ==================== Replicate Tests ====================

    public function testReplicateCreatesClone(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    public function testReplicateExceptExcludesAttributes(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    // ==================== Comparison Tests ====================

    public function testIsReturnsTrueForSameModel(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    public function testIsReturnsFalseForDifferentModels(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    public function testIsNotReturnsTrueForDifferentModels(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    // ==================== Array Access Tests ====================

    public function testOffsetExistsReturnsTrue(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    public function testOffsetExistsReturnsFalse(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    public function testOffsetGetReturnsAttribute(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    public function testOffsetSetSetsAttribute(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    public function testOffsetUnsetRemovesAttribute(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }

    // ==================== Query Builder Tests (require DB) ====================

    public function testStaticQueryMethodsRequireDatabase(): void
    {
        $this->markTestSkipped('Requires database connection for model booting');
    }
}

/**
 * Basic test model
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class TestModel extends Model
{
    protected string $table = 'test_models';
    protected string $primaryKey = 'id';
    public bool $timestamps = true;
}

/**
 * Model with fillable attributes
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class FillableModel extends Model
{
    protected string $table = 'fillable_models';
    protected array $fillable = ['name', 'email'];
    public bool $timestamps = true;
}

/**
 * Model with hidden attributes
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class HiddenModel extends Model
{
    protected string $table = 'hidden_models';
    protected array $hidden = ['password'];
    public bool $timestamps = true;
}

/**
 * Test scope implementation
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class TestScope implements Scope
{
    public function apply(Builder $builder, object $model): void
    {
        $builder->where('active', true);
    }
}
