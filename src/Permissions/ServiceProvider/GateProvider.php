<?php

declare(strict_types=1);

namespace Glueful\Permissions\ServiceProvider;

use Glueful\Container\Definition\{DefinitionInterface, FactoryDefinition};
use Glueful\Permissions\{Gate, PolicyRegistry};
use Glueful\Permissions\Voters\{SuperRoleVoter, RoleVoter, ScopeVoter, OwnershipVoter};
use Glueful\Container\Providers\BaseServiceProvider;

/**
 * Gate Service Provider
 *
 * Provides Gate and PolicyRegistry services with proper dependency injection
 * using the framework's container definition system.
 */
final class GateProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // Main Gate service
        $defs[Gate::class] = new FactoryDefinition(
            Gate::class,
            function (\Psr\Container\ContainerInterface $c): Gate {
                $config = $c->get('config')['permissions'] ?? [];

                $gate = new Gate(
                    $config['strategy'] ?? 'affirmative',
                    (bool) ($config['allow_deny_override'] ?? false)
                );

                // 1. Register super role voter if configured
                if (isset($config['super_roles']) && count($config['super_roles']) > 0) {
                    $gate->registerVoter(new SuperRoleVoter($config['super_roles']));
                }

                // 2. Register policy voter to connect PolicyRegistry
                if ($c->has(PolicyRegistry::class)) {
                    $gate->registerVoter(new \Glueful\Permissions\Voters\PolicyVoter(
                        $c->get(PolicyRegistry::class)
                    ));
                }

                // 3. Register role voter with configured roles
                $gate->registerVoter(new RoleVoter($config['roles'] ?? []));

                // 4. Register scope voter
                $gate->registerVoter(new ScopeVoter());

                // 5. Register ownership voter
                $gate->registerVoter(new OwnershipVoter());

                return $gate;
            }
        );

        // Policy registry service
        $defs[PolicyRegistry::class] = new FactoryDefinition(
            PolicyRegistry::class,
            fn(\Psr\Container\ContainerInterface $c) => new PolicyRegistry(
                $c->get('config')['permissions']['policies'] ?? []
            )
        );

        // Individual voter services (for direct injection if needed)
        $defs[SuperRoleVoter::class] = new FactoryDefinition(
            SuperRoleVoter::class,
            fn(\Psr\Container\ContainerInterface $c) => new SuperRoleVoter(
                $c->get('config')['permissions']['super_roles'] ?? []
            )
        );

        $defs[\Glueful\Permissions\Voters\PolicyVoter::class] = new FactoryDefinition(
            \Glueful\Permissions\Voters\PolicyVoter::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Permissions\Voters\PolicyVoter(
                $c->get(PolicyRegistry::class)
            )
        );

        $defs[RoleVoter::class] = new FactoryDefinition(
            RoleVoter::class,
            fn(\Psr\Container\ContainerInterface $c) => new RoleVoter(
                $c->get('config')['permissions']['roles'] ?? []
            )
        );

        $defs[ScopeVoter::class] = $this->autowire(ScopeVoter::class);
        $defs[OwnershipVoter::class] = $this->autowire(OwnershipVoter::class);

        return $defs;
    }
}
