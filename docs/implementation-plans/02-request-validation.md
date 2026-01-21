# Request Validation with Attributes Implementation Plan

> ✅ **STATUS: IMPLEMENTED** - This feature is complete and available in v1.10.0

> A comprehensive plan for implementing declarative request validation using PHP 8 attributes and Form Request classes.

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Goals and Non-Goals](#goals-and-non-goals)
3. [Current State Analysis](#current-state-analysis)
4. [Architecture Design](#architecture-design)
5. [Validation Attribute](#validation-attribute)
6. [Form Request Classes](#form-request-classes)
7. [Validation Rules](#validation-rules)
8. [Error Response Format](#error-response-format)
9. [Middleware Integration](#middleware-integration)
10. [Implementation Phases](#implementation-phases)
11. [Testing Strategy](#testing-strategy)
12. [Migration Path](#migration-path)
13. [API Reference](#api-reference)

---

## Executive Summary

This document outlines the implementation of a declarative request validation system for Glueful Framework. The system will provide:

- **`#[Validate]` attribute** for inline validation rules on controller methods
- **Form Request classes** for complex validation logic
- **Automatic validation** in the middleware pipeline
- **Standardized error responses** (422 Unprocessable Entity)
- **Custom validation rules** via classes

The implementation builds on the existing `Validator` class and integrates with the routing attribute system.

---

## Goals and Non-Goals

### Goals

- ✅ Declarative validation using PHP 8 attributes
- ✅ Form Request classes for complex validation
- ✅ Automatic validation in middleware pipeline
- ✅ Standardized 422 validation error responses
- ✅ Build on existing Validator infrastructure
- ✅ Support for custom validation rules
- ✅ IDE autocompletion for validation rules

### Non-Goals

- ❌ Replace existing Validator (enhancement, not replacement)
- ❌ Client-side validation generation
- ❌ Real-time validation endpoints
- ❌ GraphQL input validation (separate concern)

---

## Current State Analysis

### Existing Infrastructure

Glueful has a functional validation system:

```
src/Validation/
├── Validator.php              # Core validator
├── ValidationException.php    # Validation failure exception
├── Contracts/
│   ├── Rule.php               # Rule interface
│   ├── MutatingRule.php       # Mutating rule interface
│   └── ValidatorInterface.php # Validator interface
├── Rules/
│   ├── Required.php           # Required field rule
│   ├── Email.php              # Email validation
│   ├── Length.php             # String length
│   ├── Range.php              # Numeric range
│   ├── Type.php               # Type validation
│   ├── InArray.php            # Enum validation
│   ├── DbUnique.php           # Database uniqueness
│   ├── Regex.php              # Pattern matching
│   ├── Numeric.php            # Numeric validation
│   └── Sanitize.php           # Input sanitization
└── Support/
    ├── Rules.php              # Rule builder helpers
    └── Coerce.php             # Type coercion
```

### Current Usage Pattern

```php
// Current: Manual validation
public function store(Request $request): Response
{
    $validator = new Validator([
        'email' => [new Required(), new Email()],
        'password' => [new Required(), new Length(min: 8)],
    ]);

    $errors = $validator->validate($request->all());

    if (!empty($errors)) {
        return Response::error('Validation failed', 422, ['errors' => $errors]);
    }

    // Continue with validated data...
}
```

### Gaps to Fill

| Gap | Solution |
|-----|----------|
| Manual validation calls | Automatic via middleware |
| No attribute syntax | `#[Validate]` attribute |
| No Form Request | `FormRequest` base class |
| Verbose rule setup | String-based rule syntax |
| Inconsistent responses | Standardized `ValidationException` |

---

## Architecture Design

### High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                         Request Flow                            │
│                                                                 │
│  Request → ValidationMiddleware → Controller → Response         │
│                    │                                            │
│                    ▼                                            │
│            ┌──────────────────┐                                 │
│            │ Check for        │                                 │
│            │ #[Validate] or   │                                 │
│            │ FormRequest      │                                 │
│            └──────────────────┘                                 │
│                    │                                            │
│           ┌───────┴───────┐                                    │
│           ▼               ▼                                     │
│    ┌─────────────┐ ┌─────────────────┐                         │
│    │ Attribute   │ │ FormRequest     │                         │
│    │ Validation  │ │ Validation      │                         │
│    └─────────────┘ └─────────────────┘                         │
│           │               │                                     │
│           └───────┬───────┘                                    │
│                   ▼                                             │
│            ┌──────────────────┐                                 │
│            │    Validator     │                                 │
│            │   (existing)     │                                 │
│            └──────────────────┘                                 │
│                   │                                             │
│          Pass ────┴──── Fail                                    │
│            │              │                                     │
│            ▼              ▼                                     │
│       Controller    ValidationException                         │
│                     (422 Response)                              │
└─────────────────────────────────────────────────────────────────┘
```

### Directory Structure

```
src/Validation/
├── ...existing...
│
├── Attributes/
│   └── Validate.php            # #[Validate] attribute
│
├── FormRequest.php             # Base FormRequest class
├── ValidatedRequest.php        # Request wrapper with validated data
│
├── Middleware/
│   └── ValidationMiddleware.php # Auto-validation middleware
│
├── Rules/
│   ├── ...existing...
│   ├── Confirmed.php           # Password confirmation
│   ├── Date.php                # Date validation
│   ├── Url.php                 # URL validation
│   ├── Uuid.php                # UUID validation
│   ├── Json.php                # JSON string validation
│   ├── File.php                # File upload validation
│   ├── Image.php               # Image file validation
│   ├── Dimensions.php          # Image dimensions
│   ├── Exists.php              # Database existence check
│   ├── Between.php             # Between two values
│   ├── GreaterThan.php         # Greater than comparison
│   ├── LessThan.php            # Less than comparison
│   ├── Nullable.php            # Nullable field
│   ├── Sometimes.php           # Conditional validation
│   └── ArrayRule.php           # Array validation
│
├── Support/
│   ├── ...existing...
│   └── RuleParser.php          # Parse string rules to objects
│
└── Concerns/
    └── ValidatesRequests.php   # Trait for controllers
```

---

## Validation Attribute

### Attribute Definition

```php
<?php

declare(strict_types=1);

namespace Glueful\Validation\Attributes;

use Attribute;

/**
 * Validate attribute for declarative request validation
 *
 * Apply to controller methods to automatically validate incoming requests.
 *
 * @example
 * #[Validate([
 *     'email' => 'required|email|unique:users',
 *     'password' => 'required|min:8|confirmed',
 *     'name' => 'required|string|max:255',
 * ])]
 * public function store(ValidatedRequest $request): Response
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Validate
{
    /**
     * @param array<string, string|array<\Glueful\Validation\Contracts\Rule>> $rules
     * @param array<string, string> $messages Custom error messages
     * @param array<string, string> $attributes Custom attribute names
     * @param bool $stopOnFirstFailure Stop validation on first failure
     */
    public function __construct(
        public readonly array $rules,
        public readonly array $messages = [],
        public readonly array $attributes = [],
        public readonly bool $stopOnFirstFailure = false,
    ) {
    }
}
```

### Usage Examples

```php
<?php

namespace App\Http\Controllers;

use Glueful\Validation\Attributes\Validate;
use Glueful\Validation\ValidatedRequest;
use Glueful\Http\Response;

class UserController
{
    /**
     * Basic validation with string rules
     */
    #[Validate([
        'email' => 'required|email|unique:users',
        'password' => 'required|min:8',
        'name' => 'required|string|max:255',
    ])]
    public function store(ValidatedRequest $request): Response
    {
        $user = User::create($request->validated());
        return Response::created($user);
    }

    /**
     * Validation with custom messages
     */
    #[Validate(
        rules: [
            'email' => 'required|email',
            'age' => 'required|integer|min:18',
        ],
        messages: [
            'email.required' => 'We need your email address.',
            'age.min' => 'You must be at least 18 years old.',
        ]
    )]
    public function register(ValidatedRequest $request): Response
    {
        // ...
    }

    /**
     * Validation with custom attribute names
     */
    #[Validate(
        rules: [
            'dob' => 'required|date|before:today',
        ],
        attributes: [
            'dob' => 'date of birth',
        ]
    )]
    public function updateProfile(ValidatedRequest $request): Response
    {
        // Error: "The date of birth must be before today."
    }

    /**
     * Using Rule objects instead of strings
     */
    #[Validate([
        'email' => [new Required(), new Email(), new DbUnique('users', 'email')],
        'status' => [new Required(), new InArray(['active', 'inactive'])],
    ])]
    public function update(ValidatedRequest $request): Response
    {
        // ...
    }
}
```

---

## Form Request Classes

### Base FormRequest Class

```php
<?php

declare(strict_types=1);

namespace Glueful\Validation;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Validation\Contracts\Rule;

/**
 * Base FormRequest class for complex validation scenarios
 *
 * Extend this class to encapsulate validation logic, authorization,
 * and request preparation in a single, testable class.
 */
abstract class FormRequest
{
    /**
     * The underlying HTTP request
     */
    protected Request $request;

    /**
     * The validated data
     *
     * @var array<string, mixed>
     */
    protected array $validated = [];

    /**
     * The validator instance
     */
    protected Validator $validator;

    /**
     * Create a new FormRequest instance
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Get the validation rules that apply to the request
     *
     * @return array<string, string|array<Rule>>
     */
    abstract public function rules(): array;

    /**
     * Determine if the user is authorized to make this request
     *
     * Override this method to add authorization logic.
     * Return false to automatically return a 403 response.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get custom messages for validation errors
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * Get custom attribute names for validation errors
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [];
    }

    /**
     * Prepare the data for validation
     *
     * Override this method to modify request data before validation.
     */
    protected function prepareForValidation(): void
    {
        // Override in subclass
    }

    /**
     * Handle a passed validation attempt
     *
     * Called after validation passes, before the controller method.
     */
    protected function passedValidation(): void
    {
        // Override in subclass
    }

    /**
     * Handle a failed validation attempt
     *
     * Override to customize the exception thrown on failure.
     *
     * @param array<string, array<string>> $errors
     * @throws ValidationException
     */
    protected function failedValidation(array $errors): void
    {
        throw new ValidationException($errors, $this->messages());
    }

    /**
     * Handle a failed authorization attempt
     *
     * @throws \Glueful\Http\Exceptions\AuthorizationException
     */
    protected function failedAuthorization(): void
    {
        throw new \Glueful\Http\Exceptions\AuthorizationException(
            'This action is unauthorized.'
        );
    }

    /**
     * Validate the request
     *
     * @throws ValidationException
     * @throws \Glueful\Http\Exceptions\AuthorizationException
     */
    public function validate(): void
    {
        // Check authorization first
        if (!$this->authorize()) {
            $this->failedAuthorization();
        }

        // Prepare data
        $this->prepareForValidation();

        // Build and run validator
        $rules = $this->parseRules($this->rules());
        $this->validator = new Validator($rules);

        $errors = $this->validator->validate($this->all());

        if (!empty($errors)) {
            $this->failedValidation($errors);
        }

        $this->validated = $this->validator->filtered();
        $this->passedValidation();
    }

    /**
     * Parse string rules into Rule objects
     *
     * @param array<string, string|array<Rule>> $rules
     * @return array<string, array<Rule>>
     */
    protected function parseRules(array $rules): array
    {
        $parser = new Support\RuleParser();
        return $parser->parse($rules);
    }

    /**
     * Get all input data from the request
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge(
            $this->request->query->all(),
            $this->request->request->all(),
            $this->request->files->all()
        );
    }

    /**
     * Get the validated data
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        return $this->validated;
    }

    /**
     * Get a specific validated input value
     */
    public function validated_input(string $key, mixed $default = null): mixed
    {
        return $this->validated[$key] ?? $default;
    }

    /**
     * Get a specific input value
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * Check if the request has a specific input
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * Get the underlying request
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * Dynamically access request input
     */
    public function __get(string $name): mixed
    {
        return $this->input($name);
    }

    /**
     * Check if input exists
     */
    public function __isset(string $name): bool
    {
        return $this->has($name);
    }
}
```

### Example Form Requests

```php
<?php

namespace App\Http\Requests;

use Glueful\Validation\FormRequest;
use Glueful\Validation\Rules\Required;
use Glueful\Validation\Rules\Email;
use Glueful\Validation\Rules\Length;
use Glueful\Validation\Rules\DbUnique;

/**
 * Create User Request
 *
 * Encapsulates all validation logic for user creation.
 */
class CreateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request
     */
    public function authorize(): bool
    {
        // Only admins can create users
        return $this->request->attributes->get('user')?->isAdmin() ?? false;
    }

    /**
     * Get the validation rules
     */
    public function rules(): array
    {
        return [
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8|confirmed',
            'name' => 'required|string|max:255',
            'role' => 'sometimes|in:user,admin,moderator',
        ];
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'This email address is already registered.',
            'password.confirmed' => 'Password confirmation does not match.',
            'password.min' => 'Password must be at least 8 characters.',
        ];
    }

    /**
     * Get custom attribute names
     */
    public function attributes(): array
    {
        return [
            'email' => 'email address',
        ];
    }

    /**
     * Prepare data before validation
     */
    protected function prepareForValidation(): void
    {
        // Normalize email to lowercase
        if ($this->has('email')) {
            $this->request->request->set('email', strtolower($this->input('email')));
        }
    }
}

/**
 * Update User Request
 *
 * Validation for updating existing users.
 */
class UpdateUserRequest extends FormRequest
{
    public function rules(): array
    {
        $userId = $this->request->attributes->get('route_params')['id'] ?? null;

        return [
            'email' => "sometimes|email|unique:users,email,{$userId}",
            'name' => 'sometimes|string|max:255',
            'password' => 'sometimes|min:8|confirmed',
            'avatar' => 'sometimes|image|max:2048|dimensions:min_width=100,min_height=100',
        ];
    }
}

/**
 * Login Request
 */
class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required|string',
            'remember' => 'sometimes|boolean',
        ];
    }

    /**
     * Throttle login attempts
     */
    public function authorize(): bool
    {
        // Check rate limiting
        $key = 'login:' . $this->input('email');
        return !RateLimiter::tooManyAttempts($key, 5);
    }

    protected function failedAuthorization(): void
    {
        throw new TooManyRequestsException(
            'Too many login attempts. Please try again later.'
        );
    }
}
```

---

## Validation Rules

### Rule Parser

```php
<?php

declare(strict_types=1);

namespace Glueful\Validation\Support;

use Glueful\Validation\Contracts\Rule;
use Glueful\Validation\Rules;

/**
 * Parses string-based validation rules into Rule objects
 *
 * Supports Laravel-style syntax: 'required|email|max:255'
 */
class RuleParser
{
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
        'between' => Rules\Between::class,
        'in' => Rules\InArray::class,
        'not_in' => Rules\NotInArray::class,
        'regex' => Rules\Regex::class,
        'unique' => Rules\DbUnique::class,
        'exists' => Rules\Exists::class,
        'confirmed' => Rules\Confirmed::class,
        'date' => Rules\Date::class,
        'before' => Rules\Before::class,
        'after' => Rules\After::class,
        'url' => Rules\Url::class,
        'uuid' => Rules\Uuid::class,
        'json' => Rules\Json::class,
        'nullable' => Rules\Nullable::class,
        'sometimes' => Rules\Sometimes::class,
        'image' => Rules\Image::class,
        'file' => Rules\File::class,
        'dimensions' => Rules\Dimensions::class,
    ];

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
        if (is_array($rules) && isset($rules[0]) && $rules[0] instanceof Rule) {
            return $rules;
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

        foreach (explode('|', $rules) as $rule) {
            $parsed[] = $this->parseRule($rule);
        }

        return array_filter($parsed);
    }

    /**
     * Parse a single rule string
     */
    protected function parseRule(string $rule): ?Rule
    {
        // Handle rule:parameters syntax
        $parts = explode(':', $rule, 2);
        $ruleName = strtolower(trim($parts[0]));
        $parameters = isset($parts[1]) ? explode(',', $parts[1]) : [];

        if (!isset($this->ruleMap[$ruleName])) {
            // Check for custom rules
            return $this->resolveCustomRule($ruleName, $parameters);
        }

        return $this->createRule($ruleName, $parameters);
    }

    /**
     * Create a rule instance with parameters
     *
     * @param array<string> $parameters
     */
    protected function createRule(string $name, array $parameters): Rule
    {
        $class = $this->ruleMap[$name];

        return match ($name) {
            'required' => new $class(),
            'email' => new $class(),
            'string' => new Rules\Type('string'),
            'integer', 'int' => new Rules\Type('integer'),
            'boolean', 'bool' => new Rules\Type('boolean'),
            'array' => new Rules\Type('array'),
            'numeric' => new $class(),
            'min' => new Rules\Length(min: (int) ($parameters[0] ?? 0)),
            'max' => new Rules\Length(max: (int) ($parameters[0] ?? PHP_INT_MAX)),
            'between' => new Rules\Between(
                min: (int) ($parameters[0] ?? 0),
                max: (int) ($parameters[1] ?? PHP_INT_MAX)
            ),
            'in' => new Rules\InArray($parameters),
            'not_in' => new Rules\NotInArray($parameters),
            'regex' => new Rules\Regex($parameters[0] ?? '/.*/'),
            'unique' => $this->createUniqueRule($parameters),
            'exists' => $this->createExistsRule($parameters),
            'confirmed' => new Rules\Confirmed(),
            'date' => new Rules\Date($parameters[0] ?? null),
            'before' => new Rules\Before($parameters[0] ?? 'now'),
            'after' => new Rules\After($parameters[0] ?? 'now'),
            'url' => new Rules\Url(),
            'uuid' => new Rules\Uuid(),
            'json' => new Rules\Json(),
            'nullable' => new Rules\Nullable(),
            'sometimes' => new Rules\Sometimes(),
            'image' => new Rules\Image($parameters),
            'file' => new Rules\File($parameters),
            'dimensions' => new Rules\Dimensions($this->parseDimensions($parameters)),
            default => throw new \InvalidArgumentException("Unknown rule: {$name}"),
        };
    }

    /**
     * Create unique rule from parameters
     * Format: unique:table,column,except_id
     */
    protected function createUniqueRule(array $parameters): Rules\DbUnique
    {
        return new Rules\DbUnique(
            table: $parameters[0] ?? '',
            column: $parameters[1] ?? null,
            exceptId: $parameters[2] ?? null
        );
    }

    /**
     * Create exists rule from parameters
     * Format: exists:table,column
     */
    protected function createExistsRule(array $parameters): Rules\Exists
    {
        return new Rules\Exists(
            table: $parameters[0] ?? '',
            column: $parameters[1] ?? 'id'
        );
    }

    /**
     * Parse dimensions parameters
     * Format: dimensions:min_width=100,min_height=100,max_width=1000
     *
     * @param array<string> $parameters
     * @return array<string, int>
     */
    protected function parseDimensions(array $parameters): array
    {
        $dimensions = [];

        foreach ($parameters as $param) {
            if (str_contains($param, '=')) {
                [$key, $value] = explode('=', $param, 2);
                $dimensions[$key] = (int) $value;
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
        // Check for app-defined rules
        $className = 'App\\Validation\\Rules\\' . ucfirst($name);

        if (class_exists($className)) {
            return new $className(...$parameters);
        }

        // Check DI container
        $containerKey = "validation.rules.{$name}";
        if (app()->has($containerKey)) {
            return app()->get($containerKey);
        }

        return null;
    }

    /**
     * Register a custom rule mapping
     *
     * @param class-string<Rule> $class
     */
    public function extend(string $name, string $class): void
    {
        $this->ruleMap[strtolower($name)] = $class;
    }
}
```

### New Validation Rules

```php
<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

/**
 * Confirmed rule - validates that a field matches its _confirmation counterpart
 */
class Confirmed implements Rule
{
    public function validate(mixed $value, array $context = []): ?string
    {
        $field = $context['field'] ?? '';
        $confirmationField = $field . '_confirmation';
        $confirmationValue = $context['data'][$confirmationField] ?? null;

        if ($value !== $confirmationValue) {
            return "The {$field} confirmation does not match.";
        }

        return null;
    }
}

/**
 * Date rule - validates date format
 */
class Date implements Rule
{
    public function __construct(
        private ?string $format = null
    ) {
    }

    public function validate(mixed $value, array $context = []): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($this->format !== null) {
            $date = \DateTime::createFromFormat($this->format, (string) $value);
            if ($date === false || $date->format($this->format) !== $value) {
                return "The {$context['field']} must be a valid date in format {$this->format}.";
            }
        } else {
            if (strtotime((string) $value) === false) {
                return "The {$context['field']} must be a valid date.";
            }
        }

        return null;
    }
}

/**
 * Before rule - validates date is before another date
 */
class Before implements Rule
{
    public function __construct(
        private string $date
    ) {
    }

    public function validate(mixed $value, array $context = []): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);
        $beforeTimestamp = $this->date === 'today' || $this->date === 'now'
            ? time()
            : strtotime($this->date);

        if ($timestamp === false || $beforeTimestamp === false) {
            return "The {$context['field']} must be a valid date.";
        }

        if ($timestamp >= $beforeTimestamp) {
            return "The {$context['field']} must be before {$this->date}.";
        }

        return null;
    }
}

/**
 * Url rule - validates URL format
 */
class Url implements Rule
{
    public function validate(mixed $value, array $context = []): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return "The {$context['field']} must be a valid URL.";
        }

        return null;
    }
}

/**
 * Uuid rule - validates UUID format
 */
class Uuid implements Rule
{
    public function validate(mixed $value, array $context = []): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

        if (!preg_match($pattern, (string) $value)) {
            return "The {$context['field']} must be a valid UUID.";
        }

        return null;
    }
}

/**
 * Exists rule - validates that a value exists in a database table
 */
class Exists implements Rule
{
    public function __construct(
        private string $table,
        private string $column = 'id'
    ) {
    }

    public function validate(mixed $value, array $context = []): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $queryBuilder = app(\Glueful\Database\QueryBuilder::class);

        $exists = $queryBuilder
            ->from($this->table)
            ->where($this->column, $value)
            ->exists();

        if (!$exists) {
            return "The selected {$context['field']} is invalid.";
        }

        return null;
    }
}

/**
 * Nullable rule - allows null values (stops further validation if null)
 */
class Nullable implements Rule
{
    public function validate(mixed $value, array $context = []): ?string
    {
        // This rule is handled specially by the validator
        // If value is null, stop validation chain
        return null;
    }

    public function allowsNull(): bool
    {
        return true;
    }
}

/**
 * Sometimes rule - only validate if field is present
 */
class Sometimes implements Rule
{
    public function validate(mixed $value, array $context = []): ?string
    {
        // This rule is handled specially by the validator
        return null;
    }

    public function isOptional(): bool
    {
        return true;
    }
}
```

---

## Error Response Format

### ValidationException

```php
<?php

declare(strict_types=1);

namespace Glueful\Validation;

use Glueful\Http\Exceptions\HttpException;

/**
 * Validation Exception
 *
 * Thrown when request validation fails. Automatically renders
 * as a 422 Unprocessable Entity response.
 */
class ValidationException extends HttpException
{
    /**
     * @param array<string, array<string>> $errors
     * @param array<string, string> $messages
     */
    public function __construct(
        private array $errors,
        private array $customMessages = [],
        string $message = 'The given data was invalid.'
    ) {
        parent::__construct(422, $message);
    }

    /**
     * Get all validation errors
     *
     * @return array<string, array<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Get errors for a specific field
     *
     * @return array<string>
     */
    public function errorsFor(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * Check if there are errors for a field
     */
    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]) && !empty($this->errors[$field]);
    }

    /**
     * Get the response representation
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => $this->formatErrors(),
        ];
    }

    /**
     * Format errors for response
     *
     * @return array<string, array<string>>
     */
    protected function formatErrors(): array
    {
        $formatted = [];

        foreach ($this->errors as $field => $messages) {
            $formatted[$field] = array_map(function ($message) use ($field) {
                return $this->customMessages["{$field}.{$message}"]
                    ?? $this->customMessages[$field]
                    ?? $message;
            }, $messages);
        }

        return $formatted;
    }
}
```

### Standard Error Response

```json
{
    "success": false,
    "message": "The given data was invalid.",
    "errors": {
        "email": [
            "The email field is required.",
            "The email must be a valid email address."
        ],
        "password": [
            "The password must be at least 8 characters."
        ],
        "name": [
            "The name may not be greater than 255 characters."
        ]
    }
}
```

---

## Middleware Integration

### ValidationMiddleware

```php
<?php

declare(strict_types=1);

namespace Glueful\Validation\Middleware;

use Glueful\Routing\Middleware\RouteMiddleware;
use Glueful\Validation\Attributes\Validate;
use Glueful\Validation\FormRequest;
use Glueful\Validation\ValidatedRequest;
use Glueful\Validation\Validator;
use Glueful\Validation\ValidationException;
use Glueful\Validation\Support\RuleParser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Validation Middleware
 *
 * Automatically validates incoming requests based on:
 * 1. #[Validate] attributes on controller methods
 * 2. FormRequest type-hints in controller parameters
 */
class ValidationMiddleware implements RouteMiddleware
{
    private RuleParser $ruleParser;

    public function __construct()
    {
        $this->ruleParser = new RuleParser();
    }

    public function handle(Request $request, callable $next, ...$params): Response
    {
        // Get route information from request attributes
        $controller = $request->attributes->get('_controller');
        $method = $request->attributes->get('_controller_method');

        if ($controller === null || $method === null) {
            return $next($request);
        }

        try {
            // Check for FormRequest parameter
            $formRequest = $this->resolveFormRequest($controller, $method, $request);

            if ($formRequest !== null) {
                $formRequest->validate();
                $request->attributes->set('validated', $formRequest->validated());
                $request->attributes->set('form_request', $formRequest);
                return $next($request);
            }

            // Check for #[Validate] attribute
            $validateAttribute = $this->getValidateAttribute($controller, $method);

            if ($validateAttribute !== null) {
                $this->validateWithAttribute($request, $validateAttribute);
            }

            return $next($request);

        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }
    }

    /**
     * Resolve FormRequest from method parameters
     */
    protected function resolveFormRequest(
        string|object $controller,
        string $method,
        Request $request
    ): ?FormRequest {
        $reflection = new ReflectionMethod($controller, $method);

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type === null || $type->isBuiltin()) {
                continue;
            }

            $className = $type->getName();

            if (is_subclass_of($className, FormRequest::class)) {
                return new $className($request);
            }
        }

        return null;
    }

    /**
     * Get #[Validate] attribute from method
     */
    protected function getValidateAttribute(
        string|object $controller,
        string $method
    ): ?Validate {
        $reflection = new ReflectionMethod($controller, $method);
        $attributes = $reflection->getAttributes(Validate::class);

        if (empty($attributes)) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    /**
     * Validate request using #[Validate] attribute
     *
     * @throws ValidationException
     */
    protected function validateWithAttribute(Request $request, Validate $attribute): void
    {
        $rules = $this->ruleParser->parse($attribute->rules);
        $validator = new Validator($rules);

        $data = array_merge(
            $request->query->all(),
            $request->request->all()
        );

        $errors = $validator->validate($data);

        if (!empty($errors)) {
            throw new ValidationException($errors, $attribute->messages);
        }

        // Store validated data in request
        $request->attributes->set('validated', $validator->filtered());
    }

    /**
     * Create validation error response
     */
    protected function validationErrorResponse(ValidationException $e): Response
    {
        return new \Glueful\Http\Response(
            $e->toArray(),
            422
        );
    }
}
```

### ValidatedRequest Wrapper

```php
<?php

declare(strict_types=1);

namespace Glueful\Validation;

use Symfony\Component\HttpFoundation\Request;

/**
 * Validated Request wrapper
 *
 * Provides access to validated data after validation passes.
 * Type-hint this in controller methods to get validated data.
 */
class ValidatedRequest
{
    /**
     * @param array<string, mixed> $validated
     */
    public function __construct(
        private Request $request,
        private array $validated = []
    ) {
    }

    /**
     * Create from a validated request
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            $request,
            $request->attributes->get('validated', [])
        );
    }

    /**
     * Get all validated data
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        return $this->validated;
    }

    /**
     * Get a specific validated value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->validated[$key] ?? $default;
    }

    /**
     * Get only specified keys from validated data
     *
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->validated, array_flip($keys));
    }

    /**
     * Get all validated data except specified keys
     *
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->validated, array_flip($keys));
    }

    /**
     * Check if validated data has a key
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->validated);
    }

    /**
     * Get the underlying request
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * Dynamically access validated data
     */
    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    /**
     * Check if key exists
     */
    public function __isset(string $name): bool
    {
        return $this->has($name);
    }
}
```

---

## Implementation Phases

### Phase 1: Core Infrastructure (Week 1) ✅

**Deliverables:**
- [x] `#[Validate]` attribute
- [x] `RuleParser` for string syntax
- [x] `ValidationMiddleware`
- [x] `ValidatedRequest` wrapper
- [x] Updated `ValidationException`

**Acceptance Criteria:**
```php
#[Validate(['email' => 'required|email'])]
public function store(ValidatedRequest $request): Response
{
    $email = $request->get('email'); // validated
}
```

### Phase 2: Form Requests (Week 2) ✅

**Deliverables:**
- [x] `FormRequest` base class
- [x] Authorization support
- [x] Custom messages support
- [x] `prepareForValidation` hook
- [x] DI resolution in middleware

**Acceptance Criteria:**
```php
class CreateUserRequest extends FormRequest
{
    public function rules(): array { }
    public function authorize(): bool { }
}

public function store(CreateUserRequest $request): Response
{
    $data = $request->validated();
}
```

### Phase 3: New Rules (Week 2-3) ✅

**Deliverables:**
- [x] `Confirmed` rule
- [x] `Date`, `Before`, `After` rules
- [x] `Url`, `Uuid`, `Json` rules
- [x] `Exists` rule
- [x] `Nullable`, `Sometimes` rules
- [x] `Image`, `File`, `Dimensions` rules

### Phase 4: Polish & Integration (Week 3) ✅

**Deliverables:**
- [x] `php glueful make:request` command
- [x] Complete test coverage (54 validation tests)
- [x] Documentation (inline PHPDoc)
- [ ] IDE helper generation (optional, deferred)

---

## Testing Strategy

### Unit Tests

```php
<?php

namespace Glueful\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Glueful\Validation\Support\RuleParser;

class RuleParserTest extends TestCase
{
    public function testParsesStringRules(): void
    {
        $parser = new RuleParser();

        $rules = $parser->parse([
            'email' => 'required|email|max:255',
        ]);

        $this->assertCount(3, $rules['email']);
        $this->assertInstanceOf(Rules\Required::class, $rules['email'][0]);
        $this->assertInstanceOf(Rules\Email::class, $rules['email'][1]);
        $this->assertInstanceOf(Rules\Length::class, $rules['email'][2]);
    }

    public function testParsesUniqueRule(): void
    {
        $parser = new RuleParser();

        $rules = $parser->parse([
            'email' => 'unique:users,email,123',
        ]);

        $rule = $rules['email'][0];
        $this->assertInstanceOf(Rules\DbUnique::class, $rule);
    }
}
```

### Integration Tests

```php
<?php

namespace Glueful\Tests\Integration\Validation;

use Glueful\Tests\TestCase;

class ValidationMiddlewareTest extends TestCase
{
    public function testValidationFailsWithInvalidData(): void
    {
        $response = $this->post('/api/users', [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'success',
            'message',
            'errors' => ['email'],
        ]);
    }

    public function testValidationPassesWithValidData(): void
    {
        $response = $this->post('/api/users', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'name' => 'John Doe',
        ]);

        $response->assertStatus(201);
    }
}
```

---

## API Reference

### Validation Rules

| Rule | Syntax | Description |
|------|--------|-------------|
| `required` | `required` | Field must be present and not empty |
| `email` | `email` | Must be valid email |
| `string` | `string` | Must be a string |
| `integer` | `integer` | Must be an integer |
| `boolean` | `boolean` | Must be boolean |
| `numeric` | `numeric` | Must be numeric |
| `array` | `array` | Must be an array |
| `min` | `min:8` | Minimum length/value |
| `max` | `max:255` | Maximum length/value |
| `between` | `between:1,100` | Between two values |
| `in` | `in:a,b,c` | Must be in list |
| `not_in` | `not_in:x,y,z` | Must not be in list |
| `regex` | `regex:/^[a-z]+$/` | Must match pattern |
| `unique` | `unique:table,column,except` | Must be unique in DB |
| `exists` | `exists:table,column` | Must exist in DB |
| `confirmed` | `confirmed` | Must match _confirmation |
| `date` | `date` or `date:Y-m-d` | Must be valid date |
| `before` | `before:2030-01-01` | Must be before date |
| `after` | `after:today` | Must be after date |
| `url` | `url` | Must be valid URL |
| `uuid` | `uuid` | Must be valid UUID |
| `json` | `json` | Must be valid JSON |
| `nullable` | `nullable` | Allow null values |
| `sometimes` | `sometimes` | Only validate if present |
| `image` | `image` | Must be image file |
| `file` | `file:pdf,doc` | Must be file of type |
| `dimensions` | `dimensions:min_width=100` | Image dimensions |
