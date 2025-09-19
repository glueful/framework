<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Validation\Support\Rules as RuleFactory;
use Glueful\Validation\ValidationException;
use Glueful\Validation\Rules\{Sanitize, Required, Length};

class UsernameDTO
{
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
            'username' => [new Sanitize(['trim', 'strip_tags']), new Required(), new Length(3, 30)],
        ]);
        $errors = $v->validate($input);
        if (count($errors) > 0) {
            throw new ValidationException($errors);
        }
        $data = $v->filtered();
        return new self((string)$data['username']);
    }
}
