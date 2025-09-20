<?php

declare(strict_types=1);

namespace Glueful\Validation\ServiceProvider;

use Glueful\Container\Definition\DefinitionInterface;
use Glueful\Container\Providers\BaseServiceProvider;

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
