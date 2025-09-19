<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\DefinitionInterface;

final class ValidationProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // Bind contract to lightweight validator
        $defs[\Glueful\Validation\Contracts\ValidatorInterface::class] =
            $this->autowire(\Glueful\Validation\Validator::class);

        return $defs;
    }
}
