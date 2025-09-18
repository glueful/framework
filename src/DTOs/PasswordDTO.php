<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Serialization\Attributes\{Groups, Ignore};
use Glueful\Validation\Support\Rules as RuleFactory;
use Glueful\Validation\ValidationException;
use Glueful\Validation\Rules\{Sanitize, Required, Length};

class PasswordDTO
{
    #[Groups(['password:write'])]
    #[Ignore]
    public string $password = '';

    public function __construct(string $password = '')
    {
        $this->password = $password;
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public static function from(array $input): self
    {
        $v = RuleFactory::of([
            'password' => [new Sanitize(['trim', 'strip_tags']), new Required(), new Length(8, 100)],
        ]);
        $errors = $v->validate($input);
        if (count($errors) > 0) {
            throw new ValidationException($errors);
        }
        $data = $v->filtered();
        return new self((string)$data['password']);
    }
}
