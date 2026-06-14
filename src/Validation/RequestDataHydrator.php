<?php

declare(strict_types=1);

namespace Glueful\Validation;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\Support\RuleParser;

/**
 * Hydrates a request body (decoded JSON) into a validated, typed DTO.
 *
 * v1 scope: constructor-promoted DTOs only. #[Rule] attributes are collected
 * from constructor parameters (promoted properties carry the attribute on the
 * parameter). Non-promoted public properties are out of scope.
 */
final class RequestDataHydrator
{
    /**
     * @param  class-string<RequestData> $dtoClass
     * @param  array<string,mixed>       $body
     */
    public function hydrate(string $dtoClass, array $body): RequestData
    {
        $ref  = new \ReflectionClass($dtoClass);
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            /** @var RequestData $instance */
            $instance = $ref->newInstance();
            return $instance;
        }

        // 1. Collect #[Rule] strings keyed by constructor-parameter name (v1: promoted props only).
        $rules = [];
        foreach ($ctor->getParameters() as $param) {
            foreach ($param->getAttributes(Rule::class) as $attr) {
                $rules[$param->getName()] = $attr->newInstance()->rules;
            }
        }

        // 2. Validate. validate() RETURNS errors (field => messages), never throws; sanitized
        //    values come from filtered(). The hydrator throws ValidationException on errors.
        $validated = $body;
        if ($rules !== []) {
            $validator = new Validator((new RuleParser())->parse($rules));
            $errors    = $validator->validate($body);
            if ($errors !== []) {
                throw new ValidationException($errors);
            }
            // Validator::filtered() returns one entry per ruled field, defaulting absent
            // fields to null. Only adopt sanitized values for keys the body actually sent,
            // so omitted optional params still fall through to their constructor default.
            $filtered  = array_intersect_key($validator->filtered(), $body);
            $validated = $filtered + $body;
        }

        // 3. Construct: map values to constructor params by name, coerce builtins, defaults for missing.
        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $validated)) {
                $args[] = $this->coerce($validated[$name], $param);
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $args[] = null;
            } else {
                $args[] = null; // validation should have caught a missing required value
            }
        }

        /** @var RequestData $instance */
        $instance = $ref->newInstanceArgs($args);
        return $instance;
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
}
