<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Validation\Support\Rules as RuleFactory;
use Glueful\Validation\ValidationException;
use Glueful\Validation\Rules\{Sanitize, InArray, Range};
use Glueful\Validation\Support\Coerce;

class ListResourceRequestDTO
{
    public ?string $fields = '*';
    public ?string $sort = 'created_at';
    public ?int $page = 1;
    public ?int $per_page = 25;
    public ?string $order = 'desc';

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public static function from(array $input): self
    {
        $v = RuleFactory::of([
            'fields' => [new Sanitize(['trim']), new InArray(['name', 'created_at', '*'])],
            'sort' => [new Sanitize(['trim']), new InArray(['name', 'created_at'])],
            'page' => [new Range(1, null)],
            'per_page' => [new Range(1, 100)],
            'order' => [new Sanitize(['trim', 'lower']), new InArray(['asc', 'desc'])],
        ]);

        $errors = $v->validate($input);
        if (count($errors) > 0) {
            throw new ValidationException($errors);
        }
        $data = $v->filtered();

        $dto = new self();
        $dto->fields = isset($data['fields']) ? (string)$data['fields'] : $dto->fields;
        $dto->sort = isset($data['sort']) ? (string)$data['sort'] : $dto->sort;
        $dto->page = isset($data['page']) ? Coerce::int($data['page'], $dto->page) : $dto->page;
        $dto->per_page = isset($data['per_page']) ? Coerce::int($data['per_page'], $dto->per_page) : $dto->per_page;
        $dto->order = isset($data['order']) ? (string)$data['order'] : $dto->order;
        return $dto;
    }
}
