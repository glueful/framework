<?php

declare(strict_types=1);

namespace Glueful\Support\Options;

/**
 * A very small subset of Symfony OptionsResolver for internal use.
 * Supports: defaults, required, allowed types, allowed values, normalizers, resolve.
 */
final class SimpleOptionsResolver
{
    /** @var array<string,mixed> */
    private array $defaults = [];
    /** @var array<string,bool> */
    private array $required = [];
    /** @var array<string,string[]> */
    private array $allowedTypes = [];
    /** @var array<string,callable|array<mixed>> */
    private array $allowedValues = [];
    /** @var array<string,callable> */
    private array $normalizers = [];

    /**
     * @param array<string,mixed> $defaults
     */
    public function setDefaults(array $defaults): self
    {
        $this->defaults = $defaults + $this->defaults;
        return $this;
    }

    /**
     * @param string|string[] $names
     */
    public function setRequired(string|array $names): self
    {
        foreach ((array)$names as $n) {
            $this->required[$n] = true;
        }
        return $this;
    }

    /**
     * @param string|string[] $types
     */
    public function setAllowedTypes(string $name, string|array $types): self
    {
        $this->allowedTypes[$name] = array_map('strval', (array)$types);
        return $this;
    }

    /**
     * @param array<mixed>|callable $values
     */
    public function setAllowedValues(string $name, array|callable $values): self
    {
        $this->allowedValues[$name] = $values;
        return $this;
    }

    /**
     * @param callable $normalizer function(array $options, mixed $value): mixed
     */
    public function setNormalizer(string $name, callable $normalizer): self
    {
        $this->normalizers[$name] = $normalizer;
        return $this;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function resolve(array $options): array
    {
        $resolved = $this->defaults;
        foreach ($options as $k => $v) {
            $resolved[$k] = $v;
        }

        // Required check
        foreach ($this->required as $k => $isRequired) {
            if (!array_key_exists($k, $resolved)) {
                throw new \InvalidArgumentException("Missing required option: {$k}");
            }
        }

        // Allowed types
        foreach ($this->allowedTypes as $k => $types) {
            if (!array_key_exists($k, $resolved)) {
                continue;
            }
            $v = $resolved[$k];
            if (!$this->valueMatchesTypes($v, $types)) {
                $found = gettype($v);
                throw new \InvalidArgumentException(
                    "Invalid type for '{$k}': {$found}; allowed: " . implode(',', $types)
                );
            }
        }

        // Allowed values
        foreach ($this->allowedValues as $k => $rule) {
            if (!array_key_exists($k, $resolved)) {
                continue;
            }
            $v = $resolved[$k];
            $ok = true;
            if (is_callable($rule)) {
                $ok = (bool) $rule($v);
            } elseif (is_array($rule)) {
                $ok = in_array($v, $rule, true);
            }
            if (!$ok) {
                throw new \InvalidArgumentException("Invalid value for '{$k}'.");
            }
        }

        // Normalizers
        foreach ($this->normalizers as $k => $fn) {
            if (array_key_exists($k, $resolved)) {
                $resolved[$k] = $fn($resolved, $resolved[$k]);
            }
        }

        return $resolved;
    }

    /**
     * @param mixed $v
     * @param string[] $types
     */
    private function valueMatchesTypes(mixed $v, array $types): bool
    {
        $map = [
            'string' => 'is_string',
            'int' => 'is_int', 'integer' => 'is_int',
            'bool' => 'is_bool', 'boolean' => 'is_bool',
            'array' => 'is_array',
            'float' => 'is_float', 'double' => 'is_float',
            'callable' => 'is_callable',
            'null' => fn($x) => $x === null,
        ];
        foreach ($types as $t) {
            $t = strtolower($t);
            $fn = $map[$t] ?? null;
            if ($fn !== null && $fn($v)) {
                return true;
            }
            if ($t === 'scalar' && is_scalar($v)) {
                return true;
            }
            if (class_exists($t) && $v instanceof $t) {
                return true;
            }
        }
        return false;
    }
}
