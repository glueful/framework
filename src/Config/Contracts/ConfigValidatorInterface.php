<?php

declare(strict_types=1);

namespace Glueful\Config\Contracts;

interface ConfigValidatorInterface
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $schema
     * @return array<string, mixed> Validated + defaulted config
     */
    public function validate(array $input, array $schema): array;
}
