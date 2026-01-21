<?php

declare(strict_types=1);

namespace Glueful\Validation\ServiceProvider;

use Glueful\Container\Definition\DefinitionInterface;
use Glueful\Container\Definition\FactoryDefinition;
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

        // Rule parser for string-based rule syntax
        $defs[\Glueful\Validation\Support\RuleParser::class] =
            $this->autowire(\Glueful\Validation\Support\RuleParser::class);

        // ValidationMiddleware for automatic request validation
        $defs[\Glueful\Validation\Middleware\ValidationMiddleware::class] =
            $this->autowire(\Glueful\Validation\Middleware\ValidationMiddleware::class);

        // ValidatedRequest factory - creates from current request
        $defs[\Glueful\Validation\ValidatedRequest::class] = new FactoryDefinition(
            \Glueful\Validation\ValidatedRequest::class,
            function (\Psr\Container\ContainerInterface $c) {
                $request = $c->get('request');
                return \Glueful\Validation\ValidatedRequest::fromRequest($request);
            }
        );

        return $defs;
    }
}
