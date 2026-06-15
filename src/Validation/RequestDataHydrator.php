<?php

declare(strict_types=1);

namespace Glueful\Validation;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\FromRoute;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\Contracts\ValidatesSelf;
use Glueful\Validation\Support\RuleParser;
use Glueful\Validation\Support\RuleRegistry;

/**
 * Hydrates request input into a validated, typed RequestData DTO (v2).
 *
 * v2 adds source resolution (#[FromRoute]/#[FromQuery], body default), array +
 * nested-DTO hydration via #[ArrayOf] (failing as 422, never TypeError/500), and
 * a post-hydration ValidatesSelf cross-field hook. Flat scalar DTOs behave as v1.
 */
final class RequestDataHydrator
{
    private const MAX_DEPTH = 5;

    public function __construct(private readonly ?RuleRegistry $ruleRegistry = null)
    {
    }

    /**
     * @param  class-string<RequestData> $dtoClass
     * @param  array<string,mixed>       $body
     * @param  array<string,mixed>       $route
     * @param  array<string,mixed>       $query
     */
    public function hydrate(string $dtoClass, array $body, array $route = [], array $query = []): RequestData
    {
        [$instance, $errors] = $this->build($dtoClass, $body, $route, $query, depth: 0, nested: false);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        /** @var RequestData $instance */
        return $instance;
    }

    /**
     * @param  array<string,mixed> $body
     * @param  array<string,mixed> $route
     * @param  array<string,mixed> $query
     * @return array{0: ?RequestData, 1: array<string, list<string>>}
     */
    private function build(string $dtoClass, array $body, array $route, array $query, int $depth, bool $nested): array
    {
        $ref  = new \ReflectionClass($dtoClass);
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            /** @var RequestData $instance */
            $instance = $ref->newInstance();
            return [$instance, []];
        }

        // 1. Resolve raw values by source + collect per-field #[Rule] strings.
        $raw   = [];
        $rules = [];
        foreach ($ctor->getParameters() as $param) {
            $name = $param->getName();
            $hasRoute = $param->getAttributes(FromRoute::class) !== [];
            $hasQuery = $param->getAttributes(FromQuery::class) !== [];
            if ($hasRoute && $hasQuery) {
                throw new \LogicException(
                    "{$dtoClass}::\${$name} declares both #[FromRoute] and #[FromQuery]; "
                    . 'a field has exactly one source.'
                );
            }
            if ($hasRoute || $hasQuery) {
                if ($nested) {
                    throw new \LogicException(
                        "#[FromRoute]/#[FromQuery] are only valid on a top-level RequestData DTO; "
                        . "found on nested {$dtoClass}::\${$name}."
                    );
                }
                $source = $hasRoute ? $route : $query;
            } else {
                $source = $body;
            }
            if (array_key_exists($name, $source)) {
                $raw[$name] = $source[$name];
            }
            foreach ($param->getAttributes(Rule::class) as $attr) {
                $rules[$name] = $attr->newInstance()->rules;
            }
        }

        // 2. Parent-level field rules (prove container shape). Collect errors.
        $errors    = [];
        $validated = $raw;
        if ($rules !== []) {
            $validator = new Validator((new RuleParser(null, $this->ruleRegistry))->parse($rules));
            $errors    = $validator->validate($raw);
            $filtered  = array_intersect_key($validator->filtered(), $raw);
            $validated = $filtered + $raw;
        }

        // 3. Array element handling for #[ArrayOf] fields (scalar in this task).
        foreach ($ctor->getParameters() as $param) {
            $name    = $param->getName();
            $arrayOf = $param->getAttributes(ArrayOf::class);
            if (
                $arrayOf === [] || isset($errors[$name]) || !array_key_exists($name, $validated)
                || !is_array($validated[$name])
            ) {
                continue; // no #[ArrayOf], or parent rule already failed, or not an array
            }
            $of = $arrayOf[0]->newInstance();
            if ($of->isScalar()) {
                $coerced = [];
                foreach ($validated[$name] as $i => $element) {
                    $result = $this->coerceScalar($element, $of->type);
                    if ($result['ok']) {
                        $coerced[$i] = $result['value'];
                    } else {
                        $errors["{$name}.{$i}"][] = "The {$name}.{$i} field must be of type {$of->type}.";
                    }
                }
                $validated[$name] = $coerced;
            } else {
                $elementClass = $of->dtoClass();
                if ($elementClass === null) {
                    continue;
                }
                if (!is_a($elementClass, RequestData::class, true)) {
                    throw new \LogicException(
                        "#[ArrayOf] on request DTO property '{$name}' must target a class implementing RequestData; "
                        . "{$elementClass} does not."
                    );
                }
                if ($depth + 1 >= self::MAX_DEPTH) {
                    $errors[$name][] = "The {$name} field is nested too deeply (max " . self::MAX_DEPTH . ').';
                    continue;
                }
                $built = [];
                foreach ($validated[$name] as $i => $element) {
                    if (!is_array($element)) {
                        $errors["{$name}.{$i}"][] = "The {$name}.{$i} field must be an object.";
                        continue;
                    }
                    [$child, $childErrors] = $this->build($elementClass, $element, [], [], $depth + 1, nested: true);
                    foreach ($childErrors as $field => $messages) {
                        $errors["{$name}.{$i}.{$field}"] = $messages;
                    }
                    if ($childErrors === []) {
                        $built[$i] = $child;
                    }
                }
                $validated[$name] = $built;
            }
        }

        // 3b. Required presence — a param absent from input with no default and not
        //     nullable would TypeError at construction; make it a 422 instead.
        foreach ($ctor->getParameters() as $param) {
            $name = $param->getName();
            if (
                !array_key_exists($name, $validated)
                && !$param->isDefaultValueAvailable()
                && !$param->allowsNull()
                && !isset($errors[$name])
            ) {
                $errors[$name][] = "The {$name} field is required.";
            }
        }

        // 4. Errors before construction → 422, never construct.
        if ($errors !== []) {
            return [null, $errors];
        }

        // 5. Construct. (After the gate above, an absent param here is always either
        //    defaultable or nullable — so a bare null is safe.)
        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $validated)) {
                $args[] = $this->coerce($validated[$name], $param);
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                $args[] = null; // nullable + absent + no default
            }
        }
        /** @var RequestData $instance */
        $instance = $ref->newInstanceArgs($args);

        // 6. Cross-field validation hook.
        if ($instance instanceof ValidatesSelf) {
            $selfErrors = $instance->validate();
            if ($selfErrors !== []) {
                return [null, $selfErrors];
            }
        }

        return [$instance, []];
    }

    private function coerce(mixed $value, \ReflectionParameter $param): mixed
    {
        $type = $param->getType();
        if (!$type instanceof \ReflectionNamedType || !$type->isBuiltin() || $value === null) {
            return $value;
        }
        return match ($type->getName()) {
            'int'    => is_numeric($value) ? (int) $value : $value,
            'float'  => is_numeric($value) ? (float) $value : $value,
            'bool'   => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL),
            'string' => is_scalar($value) ? (string) $value : $value,
            default  => $value,
        };
    }

    /** @return array{ok: bool, value: mixed} */
    private function coerceScalar(mixed $value, string $type): array
    {
        return match ($type) {
            'int'    => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)
                            ? ['ok' => true, 'value' => (int) $value] : ['ok' => false, 'value' => null],
            'float'  => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
                            ? ['ok' => true, 'value' => (float) $value] : ['ok' => false, 'value' => null],
            'bool'   => is_bool($value) ? ['ok' => true, 'value' => $value] : ['ok' => false, 'value' => null],
            'string' => is_string($value) ? ['ok' => true, 'value' => $value] : ['ok' => false, 'value' => null],
            default  => ['ok' => false, 'value' => null],
        };
    }
}
