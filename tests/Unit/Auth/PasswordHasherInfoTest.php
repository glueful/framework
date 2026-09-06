<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth;

use Glueful\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;

/**
 * getInfo() is a thin, typed view over password_get_info(); pins the shape so the
 * implementation can trust PHP's return type without re-checking every key.
 */
final class PasswordHasherInfoTest extends TestCase
{
    public function testInfoForABcryptHashNamesTheAlgorithmAndCost(): void
    {
        $hasher = new PasswordHasher(['cost' => 4]);
        $hash = password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]);

        $info = $hasher->getInfo($hash);

        self::assertSame('2y', $info['algo']);
        self::assertSame('bcrypt', $info['algoName']);
        self::assertSame(4, $info['options']['cost']);
    }

    public function testInfoForANonHashReportsNoAlgorithm(): void
    {
        $info = (new PasswordHasher())->getInfo('not-a-hash');

        self::assertNull($info['algo']);
        self::assertSame('unknown', $info['algoName']);
        self::assertSame([], $info['options']);
    }
}
