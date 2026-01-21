<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Http\Resources;

use Glueful\Http\Resources\JsonResource;
use Glueful\Http\Resources\Support\MissingValue;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for conditional attribute loading
 */
class ConditionalAttributesTest extends TestCase
{
    public function testWhenConditionTrueIncludesValue(): void
    {
        $resource = new class(['name' => 'John', 'secret' => 'hidden']) extends JsonResource {
            public function toArray(): array
            {
                return [
                    'name' => $this->resource['name'],
                    'secret' => $this->when(true, $this->resource['secret']),
                ];
            }
        };

        $resolved = $resource->resolve();

        $this->assertArrayHasKey('secret', $resolved);
        $this->assertEquals('hidden', $resolved['secret']);
    }

    public function testWhenConditionFalseExcludesValue(): void
    {
        $resource = new class(['name' => 'John', 'secret' => 'hidden']) extends JsonResource {
            public function toArray(): array
            {
                return [
                    'name' => $this->resource['name'],
                    'secret' => $this->when(false, $this->resource['secret']),
                ];
            }
        };

        $resolved = $resource->resolve();

        $this->assertArrayNotHasKey('secret', $resolved);
    }

    public function testWhenWithClosureCondition(): void
    {
        $resource = new class(['name' => 'John', 'age' => 25]) extends JsonResource {
            public function toArray(): array
            {
                return [
                    'name' => $this->resource['name'],
                    'adult' => $this->when(
                        fn() => $this->resource['age'] >= 18,
                        'Yes'
                    ),
                ];
            }
        };

        $resolved = $resource->resolve();

        $this->assertEquals('Yes', $resolved['adult']);
    }

    public function testWhenWithClosureValue(): void
    {
        $resource = new class(['name' => 'John', 'price' => 100]) extends JsonResource {
            public function toArray(): array
            {
                return [
                    'name' => $this->resource['name'],
                    'formatted_price' => $this->when(
                        true,
                        fn() => '$' . number_format($this->resource['price'], 2)
                    ),
                ];
            }
        };

        $resolved = $resource->resolve();

        $this->assertEquals('$100.00', $resolved['formatted_price']);
    }

    public function testWhenWithDefaultValue(): void
    {
        $resource = new class(['name' => 'John']) extends JsonResource {
            public function toArray(): array
            {
                return [
                    'name' => $this->resource['name'],
                    'status' => $this->when(false, 'active', 'inactive'),
                ];
            }
        };

        $resolved = $resource->resolve();

        $this->assertEquals('inactive', $resolved['status']);
    }

    public function testWhenNotNullIncludesNonNullValue(): void
    {
        $resource = new class(['name' => 'John', 'email' => 'john@example.com']) extends JsonResource {
            public function toArray(): array
            {
                return [
                    'name' => $this->resource['name'],
                    'email' => $this->whenNotNull($this->resource['email']),
                ];
            }
        };

        $resolved = $resource->resolve();

        $this->assertArrayHasKey('email', $resolved);
    }

    public function testWhenNotNullExcludesNullValue(): void
    {
        $resource = new class(['name' => 'John', 'email' => null]) extends JsonResource {
            public function toArray(): array
            {
                return [
                    'name' => $this->resource['name'],
                    'email' => $this->whenNotNull($this->resource['email']),
                ];
            }
        };

        $resolved = $resource->resolve();

        $this->assertArrayNotHasKey('email', $resolved);
    }

    public function testWhenNotNullWithCallback(): void
    {
        $resource = new class(['name' => 'John', 'email' => 'JOHN@EXAMPLE.COM']) extends JsonResource {
            public function toArray(): array
            {
                return [
                    'name' => $this->resource['name'],
                    'email' => $this->whenNotNull(
                        $this->resource['email'],
                        fn($email) => strtolower($email)
                    ),
                ];
            }
        };

        $resolved = $resource->resolve();

        $this->assertEquals('john@example.com', $resolved['email']);
    }

    public function testWhenNotEmptyExcludesEmptyValue(): void
    {
        $resource = new class(['name' => 'John', 'tags' => []]) extends JsonResource {
            public function toArray(): array
            {
                return [
                    'name' => $this->resource['name'],
                    'tags' => $this->whenNotEmpty($this->resource['tags']),
                ];
            }
        };

        $resolved = $resource->resolve();

        $this->assertArrayNotHasKey('tags', $resolved);
    }

    public function testWhenLoadedWithArrayData(): void
    {
        $resource = new class([
            'name' => 'John',
            'posts' => [['title' => 'Post 1'], ['title' => 'Post 2']]
        ]) extends JsonResource {
            public function toArray(): array
            {
                return [
                    'name' => $this->resource['name'],
                    'posts' => $this->whenLoaded('posts'),
                ];
            }
        };

        $resolved = $resource->resolve();

        $this->assertArrayHasKey('posts', $resolved);
        $this->assertCount(2, $resolved['posts']);
    }

    public function testWhenLoadedExcludesMissingRelation(): void
    {
        $resource = new class(['name' => 'John']) extends JsonResource {
            public function toArray(): array
            {
                return [
                    'name' => $this->resource['name'],
                    'posts' => $this->whenLoaded('posts'),
                ];
            }
        };

        $resolved = $resource->resolve();

        $this->assertArrayNotHasKey('posts', $resolved);
    }

    public function testWhenLoadedWithDefaultValue(): void
    {
        $resource = new class(['name' => 'John']) extends JsonResource {
            public function toArray(): array
            {
                return [
                    'name' => $this->resource['name'],
                    'posts' => $this->whenLoaded('posts', []),
                ];
            }
        };

        $resolved = $resource->resolve();

        $this->assertEquals([], $resolved['posts']);
    }

    public function testMergeWhenConditionTrue(): void
    {
        $resource = new class(['name' => 'John', 'is_admin' => true]) extends JsonResource {
            public function toArray(): array
            {
                return [
                    'name' => $this->resource['name'],
                    $this->mergeWhen($this->resource['is_admin'], [
                        'admin_panel_url' => '/admin',
                        'permissions' => ['all'],
                    ]),
                ];
            }
        };

        $resolved = $resource->resolve();

        $this->assertEquals('/admin', $resolved['admin_panel_url']);
        $this->assertEquals(['all'], $resolved['permissions']);
    }

    public function testMergeWhenConditionFalse(): void
    {
        $resource = new class(['name' => 'John', 'is_admin' => false]) extends JsonResource {
            public function toArray(): array
            {
                return [
                    'name' => $this->resource['name'],
                    $this->mergeWhen($this->resource['is_admin'], [
                        'admin_panel_url' => '/admin',
                    ]),
                ];
            }
        };

        $resolved = $resource->resolve();

        $this->assertArrayNotHasKey('admin_panel_url', $resolved);
    }

    public function testMissingValueIsSentinel(): void
    {
        $missing = new MissingValue();

        $this->assertTrue(MissingValue::isMissing($missing));
        $this->assertFalse(MissingValue::isMissing('value'));
        $this->assertFalse(MissingValue::isMissing(null));
    }
}
