<?php

declare(strict_types=1);

namespace Glueful\Validation\Contracts;

interface ValidatorInterface
{
    /** @param array<string, Rule[]> $rules */
    public function __construct(array $rules = []);

    /**
     * @param array<string, mixed> $data
     * @return array<string, string[]> field => [messages]
     */
    public function validate(array $data): array;
}
