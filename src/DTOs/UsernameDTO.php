<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Validation\Support\Rules as RuleFactory;
use Glueful\Validation\ValidationException;
use Glueful\Validation\Rules\{Sanitize, Required, Length};

class UsernameDTO
{
    /**
     * Storage-safe ceiling: the `users.username` column is `varchar(255)`, so this is the widest
     * value that can actually be stored. Deliberately NOT narrower — a 30-character product rule
     * belongs at an application's own input boundary, not in the framework, and an app that uses
     * a normalized email as the username needs the full width. Deliberately not unbounded either:
     * accepting more than the column holds only moves the rejection to the database.
     */
    public const MAX_LENGTH = 255;

    public string $username = '';

    public function __construct(string $username = '')
    {
        $this->username = $username;
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public static function from(array $input): self
    {
        $v = RuleFactory::of([
            'username' => [new Sanitize(['trim', 'strip_tags']), new Required(), new Length(3, self::MAX_LENGTH)],
        ]);
        $errors = $v->validate($input);
        if (count($errors) > 0) {
            throw new ValidationException($errors);
        }
        $data = $v->filtered();
        return new self((string)$data['username']);
    }
}
