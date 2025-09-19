<?php

declare(strict_types=1);

namespace Glueful\Container\Loader;

use Glueful\Container\Definition\DefinitionInterface;

interface ServicesLoader
{
    /**
     * Translate an extension DSL map into container definitions.
     *
     * @param array<string, mixed> $dsl  The raw DSL map (serviceId => spec)
     * @param string|null $providerClass For error context (who provided these defs)
     * @param bool $prod                 True when compiling for production (stricter rules)
     * @return array<string, DefinitionInterface>
     */
    public function load(array $dsl, ?string $providerClass = null, bool $prod = false): array;
}
