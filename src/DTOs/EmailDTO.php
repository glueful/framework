<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Validation\Support\Rules as RuleFactory;
use Glueful\Validation\ValidationException;
use Glueful\Validation\Rules\{Sanitize, Required, Email as EmailRule};

class EmailDTO
{
    public string $email = '';

    public function __construct(string $email = '')
    {
        $this->email = $email;
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public static function from(array $input): self
    {
        $v = RuleFactory::of([
            'email' => [new Sanitize(['trim', 'strip_tags']), new Required(), new EmailRule()],
        ]);
        $errors = $v->validate($input);
        if (count($errors) > 0) {
            throw new ValidationException($errors);
        }
        $data = $v->filtered();
        return new self((string)$data['email']);
    }
}
