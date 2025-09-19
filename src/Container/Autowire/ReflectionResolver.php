<?php

declare(strict_types=1);

namespace Glueful\Container\Autowire;

use Glueful\Container\Support\ParamBag;
use Glueful\Container\Exception\ContainerException;
use Psr\Container\ContainerInterface;

final class ReflectionResolver
{
    private static ?self $instance = null;

    /** @var array<string, \ReflectionMethod|null> */
    private array $cache = [];

    public static function shared(): self
    {
        return self::$instance ??= new self();
    }

    public function resolve(string $class, ContainerInterface $container): object
    {
        $ctor = $this->cache[$class] ??= (new \ReflectionClass($class))->getConstructor();
        if ($ctor === null) {
            return new $class();
        }

        $args = [];
        foreach ($ctor->getParameters() as $p) {
            $args[] = $this->resolveParam($p, $container);
        }
        return new $class(...$args);
    }

    private function resolveParam(\ReflectionParameter $p, ContainerInterface $c): mixed
    {
        $attrs = $p->getAttributes(Inject::class);
        if (count($attrs) > 0) {
            $meta = $attrs[0]->newInstance();
            if ($meta->id !== null) {
                return $c->get($meta->id);
            }
            if ($meta->param !== null && $c->has('param.bag')) {
                $bag = $c->get('param.bag');
                if ($bag instanceof ParamBag) {
                    return $bag->get($meta->param);
                }
            }
        }
        $t = $p->getType();
        if ($t instanceof \ReflectionNamedType && !$t->isBuiltin()) {
            if ($c->has($t->getName())) {
                return $c->get($t->getName());
            }
        }
        if ($p->isDefaultValueAvailable()) {
            return $p->getDefaultValue();
        }
        if ($p->allowsNull()) {
            return null;
        }
        $className = $p->getDeclaringClass()?->getName();
        throw new ContainerException("Cannot resolve parameter {$p->getName()} of {$className}");
    }
}
