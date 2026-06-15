<?php

declare(strict_types=1);

namespace Glueful\Validation\Support;

use Glueful\Validation\Contracts\Rule;

/**
 * Holds application-registered custom validation rules (name => Rule class).
 * Resolved as a container service; NOT a global static. Registering a name that
 * already exists throws unless $overwrite is true, preventing silent shadowing.
 * Built-in rule names are always reserved and can never be overridden.
 */
final class RuleRegistry
{
    /** @var array<string, class-string<Rule>> */
    private array $rules = [];

    /** @var array<string, true> reserved built-in rule names */
    private array $reserved;

    /** @param list<string> $reservedNames built-in rule names that must not be silently shadowed */
    public function __construct(array $reservedNames = [])
    {
        $this->reserved = array_fill_keys(array_map('strtolower', $reservedNames), true);
    }

    /** @param class-string<Rule> $ruleClass */
    public function register(string $name, string $ruleClass, bool $overwrite = false): void
    {
        if (!is_a($ruleClass, Rule::class, true)) {
            throw new \InvalidArgumentException(
                "Custom rule '{$name}' class '{$ruleClass}' must implement " . Rule::class . '.'
            );
        }
        $key = strtolower($name);
        if (isset($this->reserved[$key])) {
            // Built-in names are ALWAYS reserved — no override path. RuleParser resolves
            // built-ins before the registry, so a registered override would never be used;
            // forbidding registration keeps the contract honest.
            throw new \InvalidArgumentException(
                "Custom rule '{$name}' collides with a built-in rule name; "
                . 'built-in names are reserved and cannot be overridden.'
            );
        }
        if (!$overwrite && isset($this->rules[$key])) {
            throw new \InvalidArgumentException(
                "Custom rule '{$name}' is already registered; pass overwrite: true to replace it."
            );
        }
        $this->rules[$key] = $ruleClass;
    }

    public function has(string $name): bool
    {
        return isset($this->rules[strtolower($name)]);
    }

    /** @return class-string<Rule>|null */
    public function classFor(string $name): ?string
    {
        return $this->rules[strtolower($name)] ?? null;
    }
}
