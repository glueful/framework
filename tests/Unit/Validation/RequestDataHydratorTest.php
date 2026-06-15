<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Validation;

use Glueful\Tests\Support\Fixtures\RequestData\DualSourceFixture;
use Glueful\Tests\Support\Fixtures\RequestData\RequiredNoRuleFixture;
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
}
