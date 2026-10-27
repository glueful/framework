<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Commands\Database;

use Glueful\Console\Commands\Database\SeedCommand;
use PHPUnit\Framework\TestCase;

/**
 * `db:seed UserSeeder` only looked in Database\Seeders\, but scaffold:seeder generates app seeders
 * under App\Database\Seeders\ — so a short name never found them.
 */
final class SeedCommandResolutionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists('App\\Database\\Seeders\\ProbeAppOnlySeeder', false)) {
            eval('namespace App\\Database\\Seeders; class ProbeAppOnlySeeder {}');
        }
        if (!class_exists('Database\\Seeders\\ProbeLegacyOnlySeeder', false)) {
            eval('namespace Database\\Seeders; class ProbeLegacyOnlySeeder {}');
        }
    }

    private function resolve(?string $name): ?string
    {
        $command = (new \ReflectionClass(SeedCommand::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($command, 'resolveSeederClass');

        /** @var string|null */
        return $method->invoke($command, $name);
    }

    public function testShortNameFindsAnAppSeeder(): void
    {
        self::assertSame('App\\Database\\Seeders\\ProbeAppOnlySeeder', $this->resolve('ProbeAppOnlySeeder'));
    }

    public function testShortNameStillFindsALegacySeeder(): void
    {
        self::assertSame('Database\\Seeders\\ProbeLegacyOnlySeeder', $this->resolve('ProbeLegacyOnlySeeder'));
    }

    public function testFullyQualifiedNamesAreUsedAsGiven(): void
    {
        self::assertSame('Vendor\\Seeders\\X', $this->resolve('Vendor\\Seeders\\X'));
    }
}
