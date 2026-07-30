<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\DTOs;

use Glueful\DTOs\UsernameDTO;
use Glueful\DTOs\UserDTO;
use Glueful\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Username length is a STORAGE-safe invariant, not a product rule.
 *
 * The framework enforces required, trimmed, 3 characters minimum, and the schema's own 255
 * maximum. Anything narrower — 30 characters, slug-safe charsets, reserved names — is an
 * application policy that belongs at the app's own input boundary, because the framework cannot
 * know what a host's usernames are for. Notably, an application using a normalized email as the
 * username needs the full column width: plenty of valid addresses exceed 30 characters.
 *
 * Not unbounded, though: 255 is what the column holds, and accepting more would invite input the
 * database must then reject.
 */
final class UsernameLengthTest extends TestCase
{
    private function username(int $length): string
    {
        return str_repeat('a', $length);
    }

    public function testTheMinimumIsThreeCharacters(): void
    {
        self::assertSame('abc', UsernameDTO::from(['username' => 'abc'])->username);
    }

    public function testTwoCharactersIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        UsernameDTO::from(['username' => 'ab']);
    }

    public function testThirtyCharactersIsAccepted(): void
    {
        // The old ceiling: still valid, so no existing username becomes invalid.
        self::assertSame($this->username(30), UsernameDTO::from(['username' => $this->username(30)])->username);
    }

    public function testThirtyOneCharactersIsAccepted(): void
    {
        // The behavioural change: one past the old ceiling.
        self::assertSame($this->username(31), UsernameDTO::from(['username' => $this->username(31)])->username);
    }

    public function testTwoHundredFiftyFiveCharactersIsAccepted(): void
    {
        // The schema's width is the ceiling.
        self::assertSame($this->username(255), UsernameDTO::from(['username' => $this->username(255)])->username);
    }

    public function testTwoHundredFiftySixCharactersIsRejected(): void
    {
        // Wider than the column: rejected here rather than by the database.
        $this->expectException(ValidationException::class);
        UsernameDTO::from(['username' => $this->username(256)]);
    }

    public function testALongEmailIsAValidUsername(): void
    {
        // The motivating case: an application that uses the normalized email as the username.
        $email = 'first.middle.last.name+storefront@a-fairly-long-domain-name.example.test';
        self::assertGreaterThan(30, strlen($email));

        self::assertSame($email, UsernameDTO::from(['username' => $email])->username);
    }

    /** UserDTO validates a whole user, so name and email are required alongside the username. */
    private function userInput(string $username): array
    {
        return ['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'username' => $username];
    }

    public function testUserDtoSharesTheSameBounds(): void
    {
        // Two validators, one rule — a narrower limit here would reject what UsernameDTO accepts.
        $email = 'first.middle.last.name+storefront@a-fairly-long-domain-name.example.test';

        self::assertSame($email, UserDTO::from($this->userInput($email))->username);
        self::assertSame(
            $this->username(255),
            UserDTO::from($this->userInput($this->username(255)))->username
        );
    }

    public function testUserDtoRejectsBeyondTheColumnWidth(): void
    {
        $this->expectException(ValidationException::class);
        UserDTO::from($this->userInput($this->username(256)));
    }
}
