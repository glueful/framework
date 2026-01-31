<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\{DefinitionInterface, AliasDefinition};

final class HttpPsr15Provider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        $cfg = (array) (\function_exists('config') ? config($this->context, 'http.psr15', []) : []);
        if (($cfg['enabled'] ?? true) === false) {
            return $defs;
        }

        foreach ((array) ($cfg['popular_packages'] ?? []) as $alias => $class) {
            $alias = (string) $alias;
            $class = (string) $class;
            if ($alias === '' || $class === '' || !class_exists($class)) {
                continue;
            }
            // Expose alias to class (autowire will resolve the class on demand)
            $defs[$class] = $this->autowire($class);
            $defs['psr15.' . $alias] = new AliasDefinition('psr15.' . $alias, $class);
        }

        return $defs;
    }
}
