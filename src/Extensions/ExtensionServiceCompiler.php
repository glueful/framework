<?php

// src/Extensions/ExtensionServiceCompiler.php
declare(strict_types=1);

namespace Glueful\Extensions;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Translates Provider::services() arrays into Symfony DI definitions.
 * Supported keys per service: class, arguments, shared, alias, tags, factory.
 */
final class ExtensionServiceCompiler
{
    private string $currentProvider = 'unknown';
    /** @var array<string, string> */
    private array $serviceProviders = [];

    public function __construct(private ContainerBuilder $builder)
    {
    }

    /**
     * @param array<string, array<string,mixed>> $serviceDefs
     * @param string|null $providerClass Provider class for collision tracking
     */
    public function register(array $serviceDefs, ?string $providerClass = null): void
    {
        $this->currentProvider = $providerClass ?? 'unknown';

        foreach ($serviceDefs as $id => $def) {
            if (!is_array($def)) {
                continue; // ignore unsupported entries
            }

            $class = $def['class'] ?? (is_string($id) ? $id : null);
            if (!is_string($class)) {
                continue;
            }

            $definition = new Definition($class);
            $definition->setPublic((bool)($def['public'] ?? false)); // Private by default
            $definition->setShared((bool)($def['shared'] ?? true));

            // Arguments: "@id" => Reference('id') with validation
            $args = [];
            foreach (($def['arguments'] ?? []) as $arg) {
                if (is_string($arg)) {
                    if ($arg === '@') {
                        throw new \InvalidArgumentException(
                            "Invalid argument '@' for service '{$id}'. Expected '@service_id'."
                        );
                    }
                    if (str_starts_with($arg, '@@')) {
                        throw new \InvalidArgumentException(
                            "Invalid argument '{$arg}' for service '{$id}'. Use single '@' prefix."
                        );
                    }
                    if (str_starts_with($arg, '@')) {
                        $args[] = new Reference(substr($arg, 1));
                    } else {
                        $args[] = $arg;
                    }
                } else {
                    $args[] = $arg;
                }
            }
            if (count($args) > 0) {
                $definition->setArguments($args);
            }

            // Factory (prefer [serviceId, method] or ClassName::method)
            if (isset($def['factory'])) {
                $factory = $def['factory'];

                // Prevent closures in compiled containers
                if ($factory instanceof \Closure) {
                    throw new \InvalidArgumentException(
                        "Closures are not allowed as factories in compiled containers. " .
                        "Use ['@service','method'] or 'Class::method' instead for service: {$id}"
                    );
                }

                if (is_array($factory) && isset($factory[0], $factory[1])) {
                    $target = $factory[0];
                    if (is_string($target) && str_starts_with($target, '@')) {
                        $factory[0] = new Reference(substr($target, 1));
                    }
                    $definition->setFactory($factory);
                } elseif (is_string($factory) && str_contains($factory, '::')) {
                    $definition->setFactory($factory);
                }
            }

            // Decorator support: wrap existing services
            if (isset($def['decorate']) && $def['decorate'] !== '') {
                $decorateConfig = is_array($def['decorate']) ? $def['decorate'] : ['id' => $def['decorate']];
                $definition->setDecoratedService(
                    (string)$decorateConfig['id'],
                    $decorateConfig['inner'] ?? null,
                    (int)($decorateConfig['priority'] ?? 0)
                );
            }

            // Tags: either ["tag1", "tag2"] or [["name"=>"tag","attr"=>...], ...]
            if (isset($def['tags']) && is_array($def['tags']) && count($def['tags']) > 0) {
                foreach ($def['tags'] as $tag) {
                    if (is_string($tag)) {
                        $definition->addTag($tag);
                    } elseif (is_array($tag) && isset($tag['name'])) {
                        $attrs = $tag;
                        $name  = (string)$attrs['name'];
                        unset($attrs['name']);
                        $definition->addTag($name, $attrs);
                    }
                }
            }

            // Note: tags are only meaningful if a compiler pass consumes them.
            // Built-in passes process: event.subscriber, middleware (priority),
            // validation.rule (rule_name), console.command.

            // Collision detection with provider blame: First definition wins
            if ($this->builder->hasDefinition((string)$id)) {
                $originalProvider = $this->serviceProviders[(string)$id] ?? 'unknown';
                $classInfo = $class !== '' ? " (class {$class})" : '';
                error_log(
                    "[Extensions] Service collision for '{$id}'{$classInfo} from " .
                    "{$this->currentProvider} ignored; first was {$originalProvider}"
                );
                continue;
            }

            // Track which provider registered this service
            $this->serviceProviders[(string)$id] = $this->currentProvider;

            $this->builder->setDefinition((string)$id, $definition);

            // Aliases with collision detection
            if (isset($def['alias']) && $def['alias'] !== '') {
                foreach ((array)$def['alias'] as $alias) {
                    if ($this->builder->hasDefinition((string)$alias) || $this->builder->hasAlias((string)$alias)) {
                        $originalProvider = $this->serviceProviders[(string)$alias] ?? 'unknown';
                        $classInfo = $class !== '' ? " (class {$class})" : '';
                        error_log(
                            "[Extensions] Alias collision for '{$alias}'{$classInfo} from " .
                            "{$this->currentProvider} ignored; first was {$originalProvider}"
                        );
                        continue;
                    }
                    $this->serviceProviders[(string)$alias] = $this->currentProvider;
                    $this->builder->setAlias((string)$alias, (string)$id)->setPublic($definition->isPublic());
                }
            }
        }

        // Validate all service references to catch typos
        $this->validateReferences();
    }

    /**
     * Validate that all service references exist to catch typos at build time.
     */
    private function validateReferences(): void
    {
        $missing = [];

        foreach ($this->builder->getDefinitions() as $id => $definition) {
            foreach ($definition->getArguments() as $arg) {
                if ($arg instanceof Reference && !$this->builder->has((string)$arg)) {
                    $missing[] = [$id, (string)$arg];
                }
            }

            // Check factory references too
            $factory = $definition->getFactory();
            if (is_array($factory) && isset($factory[0]) && $factory[0] instanceof Reference) {
                $serviceId = (string)$factory[0];
                if (!$this->builder->has($serviceId)) {
                    $missing[] = [$id, $serviceId];
                }
            }

            // TODO: Also validate method calls set via setMethodCalls() if we expose that later
            // This would scan $definition->getMethodCalls() for Reference arguments
        }

        if (count($missing) > 0) {
            $errors = [];
            foreach ($missing as [$serviceId, $missingRef]) {
                $errors[] = "Service '{$serviceId}' references missing service '{$missingRef}'";
            }
            throw new \InvalidArgumentException(
                "Missing service references detected (check for typos like '@validator'):\n" .
                implode("\n", $errors)
            );
        }
    }
}
