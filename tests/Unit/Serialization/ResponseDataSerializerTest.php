<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Serialization;

use Glueful\Serialization\ResponseDataSerializer;
use Glueful\Http\Contracts\ResponseData;
use PHPUnit\Framework\TestCase;

enum PbStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

final class PbAuthorData implements ResponseData
{
    public function __construct(public string $name)
    {
    }
}

final class PbPostData implements ResponseData
{
    /** @param list<PbAuthorData> $authors */
    public function __construct(
        public string $id,
        public ?string $publishedAt,
        public PbStatus $status,
        public PbAuthorData $author,
        public array $authors = [],
    ) {
    }
}

final class ResponseDataSerializerTest extends TestCase
{
    public function testSerializesScalarsNullablesEnumsAndNesting(): void
    {
        $dto = new PbPostData('p1', null, PbStatus::Published, new PbAuthorData('Ada'), [new PbAuthorData('Lin')]);
        $out = (new ResponseDataSerializer())->toArray($dto);
        self::assertSame('p1', $out['id']);
        self::assertNull($out['publishedAt']);
        self::assertSame('published', $out['status']);          // backed enum -> value
        self::assertSame(['name' => 'Ada'], $out['author']);    // nested ResponseData -> array
        self::assertSame([['name' => 'Lin']], $out['authors']); // list of ResponseData -> array of arrays
    }

    public function testPrefersCustomToArrayWhenPresent(): void
    {
        $dto = new class implements ResponseData {
            public string $a = 'x';
            public function toArray(): array
            {
                return ['custom' => true];
            }
        };
        self::assertSame(['custom' => true], (new ResponseDataSerializer())->toArray($dto));
    }

    public function testNonArrayToArrayFallsThroughToReflection(): void
    {
        // A `toArray()` whose contract is not array<...> (e.g. inherited from a
        // base class) must NOT be honoured as the escape hatch — reflection is
        // used instead, so the serializer's own `: array` return never throws.
        $dto = new class implements ResponseData {
            public string $kept = 'yes';
            public function toArray(): string
            {
                return 'not-an-array';
            }
        };
        $out = (new ResponseDataSerializer())->toArray($dto);
        self::assertSame(['kept' => 'yes'], $out);
    }

    public function testSkipsUninitializedTypedProperties(): void
    {
        $dto = new class implements ResponseData {
            public string $set = 'yes';
            public string $unset; // never assigned
        };
        $out = (new ResponseDataSerializer())->toArray($dto);
        self::assertSame(['set' => 'yes'], $out);
        self::assertArrayNotHasKey('unset', $out);
    }

    public function testSelfReferentialDtoTerminates(): void
    {
        $dto = new class implements ResponseData {
            public string $name = 'root';
            public ?ResponseData $next = null;
        };
        $dto->next = $dto; // cycle
        $out = (new ResponseDataSerializer())->toArray($dto); // must return, not hang
        self::assertSame('root', $out['name']);
    }

    public function testSiblingReuseOfSameObjectIsNotFalselyNulled(): void
    {
        // The same object referenced from two NON-overlapping branches must serialize
        // fully both times — the visited set is per-branch (detach after subtree).
        $shared = new PbAuthorData('Ada');
        $dto = new class ($shared) implements ResponseData {
            public function __construct(public PbAuthorData $primary, public ?PbAuthorData $secondary = null)
            {
                $this->secondary = $this->primary;
            }
        };
        $out = (new ResponseDataSerializer())->toArray($dto);
        self::assertSame(['name' => 'Ada'], $out['primary']);
        self::assertSame(['name' => 'Ada'], $out['secondary']); // NOT null
    }

    public function testListOfScalarsPassesThrough(): void
    {
        $dto = new class implements ResponseData {
            /** @var list<string> */
            public array $tags = ['a', 'b', 'c'];
        };
        self::assertSame(['tags' => ['a', 'b', 'c']], (new ResponseDataSerializer())->toArray($dto));
    }
}
