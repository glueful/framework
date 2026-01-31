<?php

declare(strict_types=1);

namespace Glueful\Validation\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Validation\Contracts\Rule;
use Glueful\Validation\Rules;
use InvalidArgumentException;

/**
 * Parses string-based validation rules into Rule objects
 *
 * Supports Laravel-style syntax: 'required|email|max:255'
 *
 * @example
 * $parser = new RuleParser();
 * $rules = $parser->parse([
 *     'email' => 'required|email|max:255',
 *     'name' => 'required|string|min:2|max:100',
 * ]);
 */
class RuleParser
{
    private ?ApplicationContext $context;

    public function __construct(?ApplicationContext $context = null)
    {
        $this->context = $context;
    }

    /**
     * Rule class mappings
     *
     * @var array<string, class-string<Rule>>
     */
    protected array $ruleMap = [
        'required' => Rules\Required::class,
        'email' => Rules\Email::class,
        'string' => Rules\Type::class,
        'integer' => Rules\Type::class,
        'int' => Rules\Type::class,
        'boolean' => Rules\Type::class,
        'bool' => Rules\Type::class,
        'array' => Rules\Type::class,
        'numeric' => Rules\Numeric::class,
        'min' => Rules\Length::class,
        'max' => Rules\Length::class,
        'in' => Rules\InArray::class,
        'regex' => Rules\Regex::class,
        'unique' => Rules\DbUnique::class,
        // New rules (Phase 3)
        'confirmed' => Rules\Confirmed::class,
        'date' => Rules\Date::class,
        'before' => Rules\Before::class,
        'after' => Rules\After::class,
        'url' => Rules\Url::class,
        'uuid' => Rules\Uuid::class,
        'json' => Rules\Json::class,
        'exists' => Rules\Exists::class,
        'nullable' => Rules\Nullable::class,
        'sometimes' => Rules\Sometimes::class,
        // File rules
        'file' => Rules\File::class,
        'image' => Rules\Image::class,
        'dimensions' => Rules\Dimensions::class,
    ];

    /**
     * Custom rule mappings added at runtime
     *
     * @var array<string, class-string<Rule>>
     */
    protected array $customRules = [];

    /**
     * Parse rules array
     *
     * @param array<string, string|array<Rule>> $rules
     * @return array<string, array<Rule>>
     */
    public function parse(array $rules): array
    {
        $parsed = [];

        foreach ($rules as $field => $fieldRules) {
            $parsed[$field] = $this->parseFieldRules($fieldRules);
        }

        return $parsed;
    }

    /**
     * Parse rules for a single field
     *
     * @param string|array<Rule> $rules
     * @return array<Rule>
     */
    protected function parseFieldRules(string|array $rules): array
    {
        // Already Rule objects
        if (is_array($rules)) {
            // Check if first element is a Rule object
            if ($rules !== [] && $rules[0] instanceof Rule) {
                return $rules;
            }
        }

        // String syntax: 'required|email|max:255'
        if (is_string($rules)) {
            return $this->parseStringRules($rules);
        }

        return [];
    }

    /**
     * Parse pipe-separated string rules
     *
     * @return array<Rule>
     */
    protected function parseStringRules(string $rules): array
    {
        $parsed = [];
        $ruleStrings = $this->splitRules($rules);

        foreach ($ruleStrings as $rule) {
            $ruleInstance = $this->parseRule($rule);
            if ($ruleInstance !== null) {
                $parsed[] = $ruleInstance;
            }
        }

        return $parsed;
    }

    /**
     * Split rules string by pipe, handling regex patterns with pipes
     *
     * @return array<string>
     */
    protected function splitRules(string $rules): array
    {
        // Handle regex patterns that might contain pipes
        if (str_contains($rules, 'regex:')) {
            return $this->splitRulesWithRegex($rules);
        }

        return array_filter(explode('|', $rules), fn($r) => trim($r) !== '');
    }

    /**
     * Split rules handling regex patterns
     *
     * @return array<string>
     */
    protected function splitRulesWithRegex(string $rules): array
    {
        $result = [];
        $current = '';
        $inRegex = false;
        $regexDelimiter = null;
        $chars = str_split($rules);

        for ($i = 0; $i < count($chars); $i++) {
            $char = $chars[$i];

            // Check for regex start
            if (!$inRegex && $current === 'regex:' && isset($chars[$i])) {
                $inRegex = true;
                $regexDelimiter = $char;
                $current .= $char;
                continue;
            }

            // Check for regex end
            if ($inRegex && $char === $regexDelimiter && ($chars[$i - 1] ?? '') !== '\\') {
                // Check if this is the closing delimiter (not followed by modifiers then pipe/end)
                $remaining = substr($rules, $i + 1);
                if (preg_match('/^[a-z]*(\||$)/', $remaining)) {
                    $inRegex = false;
                }
            }

            if ($char === '|' && !$inRegex) {
                if (trim($current) !== '') {
                    $result[] = trim($current);
                }
                $current = '';
            } else {
                $current .= $char;
            }
        }

        if (trim($current) !== '') {
            $result[] = trim($current);
        }

        return $result;
    }

    /**
     * Parse a single rule string
     */
    protected function parseRule(string $rule): ?Rule
    {
        // Handle rule:parameters syntax
        $parts = explode(':', $rule, 2);
        $ruleName = strtolower(trim($parts[0]));
        $parameters = isset($parts[1]) ? $this->parseParameters($parts[1]) : [];

        // Check custom rules first
        if (isset($this->customRules[$ruleName])) {
            return $this->createCustomRule($this->customRules[$ruleName], $parameters);
        }

        if (!isset($this->ruleMap[$ruleName])) {
            // Check for app-defined rules
            return $this->resolveCustomRule($ruleName, $parameters);
        }

        return $this->createRule($ruleName, $parameters);
    }

    /**
     * Parse rule parameters
     *
     * @return array<string>
     */
    protected function parseParameters(string $paramString): array
    {
        // Handle regex patterns specially
        if (str_starts_with($paramString, '/') || str_starts_with($paramString, '#')) {
            return [$paramString];
        }

        return explode(',', $paramString);
    }

    /**
     * Create a rule instance with parameters
     *
     * @param array<string> $parameters
     */
    protected function createRule(string $name, array $parameters): Rule
    {
        return match ($name) {
            'required' => new Rules\Required(),
            'email' => new Rules\Email(),
            'string' => new Rules\Type('string'),
            'integer', 'int' => new Rules\Type('integer'),
            'boolean', 'bool' => new Rules\Type('boolean'),
            'array' => new Rules\Type('array'),
            'numeric' => new Rules\Numeric(),
            'min' => new Rules\Length(min: (int) ($parameters[0] ?? 0)),
            'max' => new Rules\Length(max: (int) ($parameters[0] ?? PHP_INT_MAX)),
            'in' => new Rules\InArray($parameters),
            'regex' => new Rules\Regex($parameters[0] ?? '/.*/'),
            'unique' => $this->createUniqueRule($parameters),
            'confirmed' => new Rules\Confirmed(),
            'date' => new Rules\Date($parameters[0] ?? null),
            'before' => new Rules\Before($parameters[0] ?? 'now'),
            'after' => new Rules\After($parameters[0] ?? 'now'),
            'url' => new Rules\Url(),
            'uuid' => new Rules\Uuid(),
            'json' => new Rules\Json(),
            'exists' => $this->createExistsRule($parameters),
            'nullable' => new Rules\Nullable(),
            'sometimes' => new Rules\Sometimes(),
            'file' => $this->createFileRule($parameters),
            'image' => $this->createImageRule($parameters),
            'dimensions' => new Rules\Dimensions($this->parseDimensions($parameters)),
            default => throw new InvalidArgumentException("Unknown rule: {$name}"),
        };
    }

    /**
     * Create unique rule from parameters
     * Format: unique:table,column,except_id
     *
     * @param array<string> $parameters
     */
    protected function createUniqueRule(array $parameters): Rules\DbUnique
    {
        $table = $parameters[0] ?? '';
        $column = $parameters[1] ?? null;
        $exceptId = $parameters[2] ?? null;

        if ($table === '') {
            throw new InvalidArgumentException('The unique rule requires a table name.');
        }

        // Use positional arguments for the string-based constructor
        return new Rules\DbUnique($table, $column, $exceptId);
    }

    /**
     * Create exists rule from parameters
     * Format: exists:table,column
     *
     * @param array<string> $parameters
     */
    protected function createExistsRule(array $parameters): Rules\Exists
    {
        $table = $parameters[0] ?? '';
        $column = $parameters[1] ?? 'id';

        if ($table === '') {
            throw new InvalidArgumentException('The exists rule requires a table name.');
        }

        // Use positional arguments
        return new Rules\Exists($table, $column);
    }

    /**
     * Create file rule from parameters
     * Format: file:pdf,doc,docx or file:pdf,doc|max:2048
     *
     * @param array<string> $parameters
     */
    protected function createFileRule(array $parameters): Rules\File
    {
        $extensions = $parameters !== [] ? $parameters : null;
        return new Rules\File($extensions);
    }

    /**
     * Create image rule from parameters
     * Format: image or image:jpeg,png
     *
     * @param array<string> $parameters
     */
    protected function createImageRule(array $parameters): Rules\Image
    {
        $types = $parameters !== [] ? $parameters : null;
        return new Rules\Image($types);
    }

    /**
     * Parse dimensions parameters
     * Format: dimensions:min_width=100,min_height=100,max_width=1000
     *
     * @param array<string> $parameters
     * @return array<string, int|string>
     */
    protected function parseDimensions(array $parameters): array
    {
        $dimensions = [];

        foreach ($parameters as $param) {
            if (str_contains($param, '=')) {
                [$key, $value] = explode('=', $param, 2);
                // Keep ratio as string, convert others to int
                $dimensions[$key] = ($key === 'ratio') ? $value : (int) $value;
            }
        }

        return $dimensions;
    }

    /**
     * Resolve a custom rule class
     *
     * @param array<string> $parameters
     */
    protected function resolveCustomRule(string $name, array $parameters): ?Rule
    {
        // Check for app-defined rules in App namespace
        $appClassName = 'App\\Validation\\Rules\\' . $this->studly($name);
        if (class_exists($appClassName) && is_subclass_of($appClassName, Rule::class)) {
            return $this->createCustomRule($appClassName, $parameters);
        }

        // Check for framework rules that might not be in the map
        $frameworkClassName = 'Glueful\\Validation\\Rules\\' . $this->studly($name);
        if (class_exists($frameworkClassName) && is_subclass_of($frameworkClassName, Rule::class)) {
            return $this->createCustomRule($frameworkClassName, $parameters);
        }

        // Check DI container if available
        if ($this->context !== null) {
            $container = container($this->context);
            if ($container->has("validation.rules.{$name}")) {
                $rule = $container->get("validation.rules.{$name}");
                if ($rule instanceof Rule) {
                    return $rule;
                }
            }
        }

        return null;
    }

    /**
     * Create a custom rule instance
     *
     * @param class-string<Rule> $class
     * @param array<string> $parameters
     */
    protected function createCustomRule(string $class, array $parameters): Rule
    {
        if ($parameters === []) {
            return new $class();
        }

        // Use reflection to determine the best way to instantiate
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        // If constructor takes a single array parameter, pass parameters as array
        if ($constructor !== null) {
            $params = $constructor->getParameters();
            if (count($params) === 1 && $params[0]->getType() instanceof \ReflectionNamedType) {
                $type = $params[0]->getType();
                if ($type->getName() === 'array') {
                    return new $class($parameters);
                }
            }
        }

        // Otherwise, spread the parameters
        return new $class(...$parameters);
    }

    /**
     * Convert string to studly case
     */
    protected function studly(string $value): string
    {
        $words = explode('_', str_replace(['-', '.'], '_', $value));
        return implode('', array_map('ucfirst', $words));
    }

    /**
     * Register a custom rule mapping
     *
     * @param class-string<Rule> $class
     */
    public function extend(string $name, string $class): self
    {
        if (!is_subclass_of($class, Rule::class)) {
            throw new InvalidArgumentException(
                "Rule class {$class} must implement " . Rule::class
            );
        }

        $this->customRules[strtolower($name)] = $class;
        return $this;
    }

    /**
     * Check if a rule is registered
     */
    public function hasRule(string $name): bool
    {
        $name = strtolower($name);
        return isset($this->ruleMap[$name]) || isset($this->customRules[$name]);
    }

    /**
     * Get all registered rule names
     *
     * @return array<string>
     */
    public function getRuleNames(): array
    {
        return array_unique(array_merge(
            array_keys($this->ruleMap),
            array_keys($this->customRules)
        ));
    }
}
