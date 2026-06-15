<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Validation;

use Glueful\Tests\Support\Fixtures\RequestData\ArrayOfNonRequestDataFixture;
use Glueful\Tests\Support\Fixtures\RequestData\DualSourceFixture;
use Glueful\Tests\Support\Fixtures\RequestData\FieldDefFixture;
use Glueful\Tests\Support\Fixtures\RequestData\HasBadNestedFixture;
use Glueful\Tests\Support\Fixtures\RequestData\NestedArrayFixture;
use Glueful\Tests\Support\Fixtures\RequestData\PublishFixture;
use Glueful\Tests\Support\Fixtures\RequestData\RecursiveFixture;
use Glueful\Tests\Support\Fixtures\RequestData\RequiredNoRuleFixture;
use Glueful\Tests\Support\Fixtures\RequestData\ScalarArrayFixture;
use Glueful\Tests\Support\Fixtures\RequestData\SourcedFixture;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\RequestDataHydrator;
use Glueful\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class CreatePostFixture implements RequestData
{
    public function __construct(
        #[Rule('required|string|max:200')] public string $title,
        #[Rule('required|string')]          public string $body,
        #[Rule('in:draft,published')]       public string $status = 'draft',
    ) {
    }
}

final class SanitizedFixture implements RequestData
{
    public function __construct(
        #[Rule('sanitize:trim|required|string')] public string $title,
    ) {
    }
}

final class RequestDataHydratorTest extends TestCase
{
    private RequestDataHydrator $hydrator;

    protected function setUp(): void
    {
        $this->hydrator = new RequestDataHydrator();
    }

    public function testHydratesValidBodyIntoDto(): void
    {
        $dto = $this->hydrator->hydrate(CreatePostFixture::class, [
            'title' => 'Hello', 'body' => 'World', 'status' => 'published',
        ]);
        self::assertInstanceOf(CreatePostFixture::class, $dto);
        self::assertSame('Hello', $dto->title);
        self::assertSame('published', $dto->status);
    }

    public function testUsesDefaultForOmittedOptional(): void
    {
        $dto = $this->hydrator->hydrate(CreatePostFixture::class, ['title' => 'T', 'body' => 'B']);
        self::assertSame('draft', $dto->status);
    }

    public function testInvalidBodyThrowsValidationException(): void
    {
        $this->expectException(ValidationException::class);
        $this->hydrator->hydrate(CreatePostFixture::class, ['body' => 'no title']); // title required
    }

    public function testSanitizedSentFieldSurvivesTheFilterIntersect(): void
    {
        // The other half of the filtered()/intersect fix: a MutatingRule's sanitized value
        // for a field the caller DID send must win over the raw body value.
        $dto = $this->hydrator->hydrate(SanitizedFixture::class, ['title' => '  Hello  ']);
        self::assertSame('Hello', $dto->title);
    }

    public function testResolvesValuesFromRouteAndQueryBySourceAttribute(): void
    {
        $dto = $this->hydrator->hydrate(
            SourcedFixture::class,
            ['title' => 'Hello', 'status' => 'should-be-ignored'],
            ['uuid' => 'abc123'],
            ['status' => 'published'],
        );
        self::assertSame('abc123', $dto->uuid);
        self::assertSame('published', $dto->status);
        self::assertSame('Hello', $dto->title);
    }

    public function testFlatV1DtoUnchanged(): void
    {
        $dto = $this->hydrator->hydrate(CreatePostFixture::class, ['title' => 'T', 'body' => 'B']);
        self::assertSame('draft', $dto->status);
    }

    public function testMissingNonNullableNoRuleIs422NotTypeError(): void
    {
        try {
            $this->hydrator->hydrate(RequiredNoRuleFixture::class, []);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('name', $e->errors());
        }
    }

    public function testBothSourceAttributesOnOneFieldThrowLogicException(): void
    {
        $this->expectException(\LogicException::class);
        $this->hydrator->hydrate(DualSourceFixture::class, [], ['x' => '1'], ['x' => '2']);
    }

    public function testCoercesScalarArrayElements(): void
    {
        $dto = $this->hydrator->hydrate(ScalarArrayFixture::class, ['ids' => ['1', '2', 3]]);
        self::assertSame([1, 2, 3], $dto->ids);
    }

    public function testNonCoercibleScalarElementIs422WithDotPath(): void
    {
        try {
            $this->hydrator->hydrate(ScalarArrayFixture::class, ['ids' => [1, 'nope']]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('ids.1', $e->errors());
        }
    }

    public function testNonArrayValueForArrayFieldIs422NotTypeError(): void
    {
        try {
            $this->hydrator->hydrate(ScalarArrayFixture::class, ['ids' => 'not-an-array']);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('ids', $e->errors()); // shallow 'array' rule fired; recursion never ran
        }
    }

    public function testHydratesNestedDtoArray(): void
    {
        $dto = $this->hydrator->hydrate(NestedArrayFixture::class, [
            'slug'   => 'post',
            'schema' => [['name' => 'title', 'type' => 'string'], ['name' => 'body', 'type' => 'text']],
        ]);
        self::assertCount(2, $dto->schema);
        self::assertInstanceOf(FieldDefFixture::class, $dto->schema[0]);
        self::assertSame('title', $dto->schema[0]->name);
    }

    public function testInvalidNestedElementIs422WithDotPath(): void
    {
        try {
            $this->hydrator->hydrate(NestedArrayFixture::class, [
                'slug'   => 'post',
                'schema' => [['name' => 'title', 'type' => 'string'], ['type' => 'text']],
            ]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('schema.1.name', $e->errors());
        }
    }

    public function testNonArrayNestedElementIs422WithDotPath(): void
    {
        try {
            $this->hydrator->hydrate(NestedArrayFixture::class, [
                'slug'   => 'post',
                'schema' => ['not-an-object'], // element 0 is a scalar, not an object
            ]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('schema.0', $e->errors());
        }
    }

    public function testNestedSourceAttributeThrowsLogicException(): void
    {
        $this->expectException(\LogicException::class);
        $this->hydrator->hydrate(HasBadNestedFixture::class, ['rows' => [['oops' => 'x']]]);
    }

    public function testDepthCapProducesValidationErrorNotOverflow(): void
    {
        // Build 6-deep nested 'children' (exceeds MAX_DEPTH=5).
        $payload = [];
        $node = &$payload;
        for ($i = 0; $i < 6; $i++) {
            $node['children'] = [[]];
            $node = &$node['children'][0];
        }
        unset($node);

        try {
            $this->hydrator->hydrate(RecursiveFixture::class, $payload);
            self::fail('expected ValidationException from the depth cap');
        } catch (ValidationException $e) {
            // The cap fires deterministically at the deepest in-bounds frame (no overflow).
            self::assertArrayHasKey('children.0.children.0.children.0.children.0.children', $e->errors());
        }
    }

    public function testValidatesSelfPasses(): void
    {
        $dto = $this->hydrator->hydrate(PublishFixture::class, ['status' => 'draft']);
        self::assertInstanceOf(PublishFixture::class, $dto);
    }

    public function testValidatesSelfErrorsBecome422(): void
    {
        try {
            $this->hydrator->hydrate(PublishFixture::class, ['status' => 'published']);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('publishedAt', $e->errors());
        }
    }

    public function testArrayOfNonRequestDataClassThrowsLogicException(): void
    {
        // A request DTO whose #[ArrayOf] targets a class that does not implement
        // RequestData is structural developer misuse — the hydrator must fail loud
        // (LogicException) rather than silently mis-hydrating, consistent with the
        // dual-source and nested-source-attribute guards.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/items.*RequestData.*NonRequestDataFixture|NonRequestDataFixture.*RequestData/i');
        $this->hydrator->hydrate(ArrayOfNonRequestDataFixture::class, ['items' => [['value' => 'x']]]);
    }
}
