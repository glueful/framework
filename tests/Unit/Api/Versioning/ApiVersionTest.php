<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Versioning;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Glueful\Api\Versioning\ApiVersion;

class ApiVersionTest extends TestCase
{
    #[Test]
    public function fromStringParsesMajorOnly(): void
    {
        $version = ApiVersion::fromString('2');

        $this->assertEquals('2', $version->major);
        $this->assertNull($version->minor);
        $this->assertNull($version->patch);
    }

    #[Test]
    public function fromStringParsesMajorMinor(): void
    {
        $version = ApiVersion::fromString('2.1');

        $this->assertEquals('2', $version->major);
        $this->assertEquals('1', $version->minor);
        $this->assertNull($version->patch);
    }

    #[Test]
    public function fromStringParsesFullSemver(): void
    {
        $version = ApiVersion::fromString('2.1.3');

        $this->assertEquals('2', $version->major);
        $this->assertEquals('1', $version->minor);
        $this->assertEquals('3', $version->patch);
    }

    #[Test]
    public function fromStringStripsVPrefix(): void
    {
        $version = ApiVersion::fromString('v2');

        $this->assertEquals('2', $version->major);
    }

    #[Test]
    public function fromStringStripsUpperVPrefix(): void
    {
        $version = ApiVersion::fromString('V2');

        $this->assertEquals('2', $version->major);
    }

    #[Test]
    public function defaultCreatesVersion1(): void
    {
        $version = ApiVersion::default();

        $this->assertEquals('1', $version->major);
        $this->assertNull($version->minor);
    }

    #[Test]
    public function toStringReturnsVersionWithoutPrefix(): void
    {
        $version = ApiVersion::fromString('2.1.3');

        $this->assertEquals('2.1.3', $version->toString());
        $this->assertEquals('2.1.3', (string) $version);
    }

    #[Test]
    public function toPrefixReturnsVWithMajor(): void
    {
        $version = ApiVersion::fromString('2.1');

        $this->assertEquals('v2', $version->toPrefix());
    }

    #[Test]
    public function equalsReturnsTrueForSameVersion(): void
    {
        $v1 = ApiVersion::fromString('2.1.3');
        $v2 = ApiVersion::fromString('2.1.3');

        $this->assertTrue($v1->equals($v2));
    }

    #[Test]
    public function equalsReturnsFalseForDifferentVersions(): void
    {
        $v1 = ApiVersion::fromString('2.1.3');
        $v2 = ApiVersion::fromString('2.1.4');

        $this->assertFalse($v1->equals($v2));
    }

    #[Test]
    public function isCompatibleWithReturnsTrueForSameMajor(): void
    {
        $v1 = ApiVersion::fromString('1.0');
        $v2 = ApiVersion::fromString('1.1');

        $this->assertTrue($v1->isCompatibleWith($v2));
    }

    #[Test]
    public function isCompatibleWithReturnsFalseForDifferentMajor(): void
    {
        $v1 = ApiVersion::fromString('1.0');
        $v2 = ApiVersion::fromString('2.0');

        $this->assertFalse($v1->isCompatibleWith($v2));
    }

    #[Test]
    #[DataProvider('compareToProvider')]
    public function compareToReturnsCorrectResult(string $v1, string $v2, int $expected): void
    {
        $version1 = ApiVersion::fromString($v1);
        $version2 = ApiVersion::fromString($v2);

        $result = $version1->compareTo($version2);

        // Normalize to -1, 0, 1
        $normalized = $result <=> 0;

        $this->assertEquals($expected, $normalized);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function compareToProvider(): array
    {
        return [
            'equal versions' => ['1', '1', 0],
            'v1 less than v2' => ['1', '2', -1],
            'v1 greater than v2' => ['2', '1', 1],
            'minor version comparison' => ['1.0', '1.1', -1],
            'patch version comparison' => ['1.0.0', '1.0.1', -1],
            'major takes precedence' => ['1.9', '2.0', -1],
        ];
    }

    #[Test]
    public function isGreaterThanWorks(): void
    {
        $v1 = ApiVersion::fromString('2');
        $v2 = ApiVersion::fromString('1');

        $this->assertTrue($v1->isGreaterThan($v2));
        $this->assertFalse($v2->isGreaterThan($v1));
    }

    #[Test]
    public function isLessThanWorks(): void
    {
        $v1 = ApiVersion::fromString('1');
        $v2 = ApiVersion::fromString('2');

        $this->assertTrue($v1->isLessThan($v2));
        $this->assertFalse($v2->isLessThan($v1));
    }

    #[Test]
    public function isWithinRangeWithNoConstraints(): void
    {
        $version = ApiVersion::fromString('2');

        $this->assertTrue($version->isWithinRange(null, null));
    }

    #[Test]
    public function isWithinRangeWithMinOnly(): void
    {
        $version = ApiVersion::fromString('2');
        $min = ApiVersion::fromString('1');

        $this->assertTrue($version->isWithinRange($min, null));
    }

    #[Test]
    public function isWithinRangeWithMaxOnly(): void
    {
        $version = ApiVersion::fromString('2');
        $max = ApiVersion::fromString('3');

        $this->assertTrue($version->isWithinRange(null, $max));
    }

    #[Test]
    public function isWithinRangeWithBothConstraints(): void
    {
        $version = ApiVersion::fromString('2');
        $min = ApiVersion::fromString('1');
        $max = ApiVersion::fromString('3');

        $this->assertTrue($version->isWithinRange($min, $max));
    }

    #[Test]
    public function isWithinRangeReturnsFalseWhenBelowMin(): void
    {
        $version = ApiVersion::fromString('1');
        $min = ApiVersion::fromString('2');

        $this->assertFalse($version->isWithinRange($min, null));
    }

    #[Test]
    public function isWithinRangeReturnsFalseWhenAboveMax(): void
    {
        $version = ApiVersion::fromString('4');
        $max = ApiVersion::fromString('3');

        $this->assertFalse($version->isWithinRange(null, $max));
    }
}
