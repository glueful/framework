
# Glueful Config & Validation — Implementation & Migration Plan (v1)
> Modeled after our earlier migration playbooks for Serializer and PSR‑14 Events for consistency across subsystems. fileciteturn0file0 fileciteturn0file1

**Date:** 2025-09-17 (UTC)  
**Scope:** Remove `symfony/options-resolver` and `symfony/config` **now**, and introduce Glueful‑native `src/Validation/` (interfaces, rules, exception, middleware hook) and `src/Config/` (schema validator, schema definitions). Glueful is not live, so we prioritize a clean break over backwards compatibility.

---

## Executive Summary

- **Drop** `symfony/options-resolver` and **replace** with typed Config DTOs (preferred) or a tiny options resolver for transitional spots.
- **Drop** `symfony/config` and **replace** with a compact **schema validator** + **typed DTO hydration** + plain PHP config files.
- **Replace** `symfony/validator` with a first-party **Validation** subsystem (lean rules: Required, Type, Length, Range, Regex, Email, Url, InArray, DateTimeFormat, DbUnique, DbExists). Migrate the 5 DTOs off Symfony attributes. Keep `ValidationException` and the 422 middleware mapping.
- **Deliverables** are production‑shaped and drop‑in: `src/Config/*`, `src/Validation/*`, tests, service providers, and composer updates.

---

## Goals

- Minimize external surface area while preserving great DX.
- Centralize error messages and envelopes (no Symfony semantics leaking).
- Keep the hot path simple and fast (array checks, typed DTOs).
- Make migration mechanical: clear steps, narrow diffs, and a rollback plan (git revert).

---

## Architecture Overview

```
src/
├─ Config/
│  ├─ Contracts/
│  │  └─ ConfigValidatorInterface.php
│  ├─ Schema/
│  │  ├─ queue.php              # returns array schema for QueueConfig
│  │  └─ *.php                  # one file per config domain
│  ├─ DTO/
│  │  └─ QueueConfig.php        # typed config objects (preferred API)
│  ├─ Validation/
│  │  └─ ConfigValidator.php    # tiny schema validator (defaults, types, enum, ranges, nesting)
│  └─ Support/
│     └─ Assert.php             # optional: tiny assert helpers
│
└─ Validation/
   ├─ Contracts/
   │  ├─ Rule.php               # validate(value, context): ?string
   │  └─ ValidatorInterface.php
   ├─ Rules/
   │  ├─ Required.php
   │  ├─ Type.php
   │  ├─ Length.php
   │  ├─ Range.php
   │  ├─ Regex.php
   │  ├─ Email.php
   │  ├─ Url.php
   │  ├─ InArray.php
   │  ├─ DateTimeFormat.php
   │  ├─ DbUnique.php
   │  └─ DbExists.php
   ├─ Validator.php
   ├─ ValidationException.php
   └─ Http/
      └─ ValidationMiddleware.php   # maps ValidationException → 422 JSON envelope
```

---

## Deliverables (Ready‑to‑Drop Code)

### 1) `src/Config/Contracts/ConfigValidatorInterface.php`
```php
<?php
declare(strict_types=1);

namespace Glueful\Config\Contracts;

interface ConfigValidatorInterface
{
    /** @return array Validated + defaulted config */
    public function validate(array $input, array $schema): array;
}
```

### 2) `src/Config/Validation/ConfigValidator.php`
```php
<?php
declare(strict_types=1);

namespace Glueful\Config\Validation;

use Glueful\Config\Contracts\ConfigValidatorInterface;

final class ConfigValidator implements ConfigValidatorInterface
{
    public function validate(array $input, array $schema): array
    {
        $out = [];
        $errors = [];

        foreach ($schema as $key => $rules) {
            $has = array_key_exists($key, $input);
            $val = $has ? $input[$key] : ($rules['default'] ?? null);

            if (($rules['required'] ?? false) && !$has) {
                $errors[] = "Missing required: {$key}";
                continue;
            }

            if ($val !== null && isset($rules['type']) && gettype($val) !== $rules['type']) {
                $errors[] = "Invalid type for {$key}: expected {$rules['type']}, got " . gettype($val);
                continue;
            }

            if (isset($rules['enum']) && $val !== null && !in_array($val, $rules['enum'], true)) {
                $errors[] = "{$key} must be one of: " . implode(', ', $rules['enum']);
            }

            if (isset($rules['min']) && is_int($val) && $val < $rules['min']) {
                $errors[] = "{$key} must be >= {$rules['min']}";
            }
            if (isset($rules['max']) && is_int($val) && $val > $rules['max']) {
                $errors[] = "{$key} must be <= {$rules['max']}";
            }

            // Nested object/array schemas (optional)
            if (isset($rules['schema']) && is_array($val) && is_array($rules['schema'])) {
                $nested = $this->validate($val, $rules['schema']);
                $val = $nested; // will throw on nested errors via exception below if needed
            }

            $out[$key] = $val;
        }

        if ($errors) {
            throw new \InvalidArgumentException('Config validation failed: ' . implode('; ', $errors));
        }

        return $out;
    }
}
```

### 3) Example Schema: `src/Config/Schema/queue.php`
```php
<?php
declare(strict_types=1);

return [
    'connection' => ['required' => true, 'type' => 'string'],
    'max_attempts' => ['required' => false, 'type' => 'integer', 'min' => 1, 'default' => 3],
    'retry_on_failure' => ['required' => false, 'type' => 'boolean', 'default' => true],
    'strategy' => ['required' => false, 'type' => 'string', 'enum' => ['immediate','delayed'], 'default' => 'immediate'],
];
```

### 4) Typed DTO: `src/Config/DTO/QueueConfig.php`
```php
<?php
declare(strict_types=1);

namespace Glueful\Config\DTO;

final class QueueConfig
{
    public function __construct(
        public string $connection,
        public int $maxAttempts = 3,
        public bool $retryOnFailure = true,
        public string $strategy = 'immediate',
    ) {}
}
```

### 5) Optional helper: `src/Config/Support/Assert.php`
```php
<?php
declare(strict_types=1);

namespace Glueful\Config\Support;

final class Assert
{
    public static function string(array $a, string $k): string {
        if (!isset($a[$k]) || !is_string($a[$k])) throw new \InvalidArgumentException("Expected string: {$k}");
        return $a[$k];
    }
}
```

---


### Minimal DTO migration pattern

```php
// Before (Symfony attributes)
final class EmailDTO {
    #[\Symfony\Component\Validator\Constraints\NotBlank]
    #[\Symfony\Component\Validator\Constraints\Email(message: 'Please provide a valid email')]
    public string $email;
}

// After (no attributes; rules declared in factory or request layer)
use Glueful\Validation\Validator;
use Glueful\Validation\Rules\{Required, Email as EmailRule};

final class EmailDTO {
    public function __construct(public string $email) {}

    /** @throws Glueful\Validation\ValidationException */
    public static function from(array $input): self {
        $v = new Validator([
            'email' => [new Required(), new EmailRule()],
        ]);
        $errors = $v->validate($input);
        if ($errors) throw new \Glueful\Validation\ValidationException($errors);
        return new self($input['email']);
    }
}
```

### Attribute → Rule mapping

| Symfony attribute                 | New rule          | Notes                                           |
|----------------------------------|-------------------|-------------------------------------------------|
| `NotBlank` / `NotNull`           | `Required`        | Empty string/array treated as missing           |
| `Email`                          | `Email`           | Uses `FILTER_VALIDATE_EMAIL`                    |
| `Length(min,max)`                | `Length`          | Inclusive bounds                                |
| `Choice(choices=[])`             | `InArray([...])`  | Strict (`===`)                                  |
| `Type(string|int|array...)`      | `Type('string'|'integer'|...)` | Skip when null unless also `Required` |

## Validation Subsystem (Ready‑to‑Drop)

### 6) Contracts
`src/Validation/Contracts/Rule.php`
```php
<?php
declare(strict_types=1);

namespace Glueful\Validation\Contracts;

interface Rule
{
    /** Return error message or null if ok */
    public function validate(mixed $value, array $context = []): ?string;
}
```

`src/Validation/Contracts/ValidatorInterface.php`
```php
<?php
declare(strict_types=1);

namespace Glueful\Validation\Contracts;

interface ValidatorInterface
{
    /** @param array<string, Rule[]> $rules */
    public function __construct(array $rules = []);

    /** @return array<string, string[]> field => [messages] */
    public function validate(array $data): array;
}
```

### 7) Core Validator
`src/Validation/Validator.php`
```php
<?php
declare(strict_types=1);

namespace Glueful\Validation;

use Glueful\Validation\Contracts\Rule;
use Glueful\Validation\Contracts\ValidatorInterface;

final class Validator implements ValidatorInterface
{
    /** @var array<string, Rule[]> */
    private array $rules;

    /** @param array<string, Rule[]> $rules */
    public function __construct(array $rules = []) { $this->rules = $rules; }

    public function validate(array $data): array
    {
        $errors = [];
        foreach ($this->rules as $field => $rules) {
            $value = $data[$field] ?? null;
            foreach ($rules as $rule) {
                $msg = $rule->validate($value, ['field' => $field, 'data' => $data]);
                if ($msg !== null) { $errors[$field][] = $msg; }
            }
        }
        return $errors;
    }
}
```

### 8) Built‑in Rules (examples)

`src/Validation/Rules/Required.php`
```php
<?php
declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

final class Required implements Rule
{
    public function validate(mixed $value, array $context = []): ?string
    {
        return ($value === null || $value === '' || (is_array($value) && $value === []))
            ? 'This field is required.'
            : null;
    }
}
```

`src/Validation/Rules/Type.php`
```php
<?php
declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

final class Type implements Rule
{
    public function __construct(private string $type) {}

    public function validate(mixed $value, array $context = []): ?string
    {
        if ($value === null) return null;
        return gettype($value) === $this->type ? null : "Expected type {$this->type}.";
    }
}
```

`src/Validation/Rules/Email.php`
```php
<?php
declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

final class Email implements Rule
{
    public function validate(mixed $value, array $context = []): ?string
    {
        if ($value === null) return null;
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Invalid email address.';
    }
}
```

`src/Validation/Rules/DbUnique.php`
```php
<?php
declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;
use PDO;

final class DbUnique implements Rule
{
    public function __construct(private PDO $pdo, private string $table, private string $column) {}

    public function validate(mixed $value, array $context = []): ?string
    {
        if ($value === null) return null;
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->table} WHERE {$this->column} = :v LIMIT 1");
        $stmt->execute(['v' => $value]);
        return $stmt->fetchColumn() ? 'Value must be unique.' : null;
    }
}
```

> Implement similarly: `Length`, `Range`, `Regex`, `Url`, `InArray`, `DateTimeFormat`, `DbExists`.

### 9) Exception & HTTP Middleware

`src/Validation/ValidationException.php`
```php
<?php
declare(strict_types=1);

namespace Glueful\Validation;

final class ValidationException extends \RuntimeException
{
    /** @param array<string, string[]> $errors */
    public function __construct(private array $errors)
    {
        parent::__construct('Validation failed.');
    }

    /** @return array<string, string[]> */
    public function errors(): array { return $this->errors; }
}
```

`src/Validation/Http/ValidationMiddleware.php`
```php
<?php
declare(strict_types=1);

namespace Glueful\Validation\Http;

use Glueful\Validation\ValidationException;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ResponseInterface as Response;

final class ValidationMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        try {
            return $handler->handle($request);
        } catch (ValidationException $e) {
            $payload = json_encode([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], JSON_THROW_ON_ERROR);

            return new \Glueful\Http\JsonResponse($payload, 422, [], true);
        }
    }
}
```

---

## Config Loading Pattern (Example)

```php
// config/queue.php (user-land config file)
return [
    'connection' => env('QUEUE_CONNECTION', 'redis'),
    'max_attempts' => 3,
    'retry_on_failure' => true,
    'strategy' => 'immediate',
];

// bootstrap/config.php
$raw = require base_path('config/queue.php');
$schema = require base_path('src/Config/Schema/queue.php'); // framework schema
$validated = $container->get(ConfigValidatorInterface::class)->validate($raw, $schema);
$dto = new \Glueful\Config\DTO\QueueConfig(
    connection: $validated['connection'],
    maxAttempts: $validated['max_attempts'],
    retryOnFailure: $validated['retry_on_failure'],
    strategy: $validated['strategy'],
);
// Bind DTO in container for consumers
$container->set(\Glueful\Config\DTO\QueueConfig::class, $dto);
```

---

## Service Provider Wiring

```php
// src/DI/ServiceProviders/ConfigServiceProvider.php
public function register($c): void
{
    $c->register(\Glueful\Config\Contracts\ConfigValidatorInterface::class, \Glueful\Config\Validation\ConfigValidator::class)
      ->setPublic(true);

    // Bind frequently used DTOs after bootstrap resolution
    // e.g., QueueConfig bound in bootstrap/config.php
}

// src/DI/ServiceProviders/ValidationServiceProvider.php
public function register($c): void
{
    $c->register(\Glueful\Validation\Contracts\ValidatorInterface::class, \Glueful\Validation\Validator::class)
      ->setPublic(true);

    // Optionally bind common rules as services if you prefer DI construction
}
```

---

## Migration Steps (Mechanical Checklist)

**Phase 1 — Remove OptionsResolver (Today)**
1. Replace 7 usages of `OptionsResolver` with **typed DTO construction** (preferred) or temporary SimpleOptionsResolver.
2. Introduce `ConfigValidator` where schema checks are desired (instead of OptionsResolver’s type/required checks).
3. Update service providers / bootstrap to hydrate DTOs.
4. `composer remove symfony/options-resolver`.

**Phase 2 — Replace symfony/config (Today)**
1. Add `src/Config/Validation/ConfigValidator.php` & `src/Config/Contracts/*`.
2. Convert TreeBuilder classes into **plain array schemas** in `src/Config/Schema/*.php`.
3. Hydrate typed DTOs after validation; bind DTOs to the container.
4. `composer remove symfony/config`.

**Phase C — Replace symfony/validator (Today/Tomorrow)**
1. Add `src/Validation/*` (contracts, rules, validator, exception, middleware).
2. Replace request validation sites to use the new `Validator` + rules; when invalid, **throw `ValidationException`**.
3. Register `ValidationMiddleware` in the HTTP pipeline so errors map to the 422 envelope.
4. Write table‑driven tests for rules; add a couple of end‑to‑end request tests.

---

## Composer Changes (excerpt)

```json
{
  "require": {
    // Remove if only used for these purposes:
    // "symfony/options-resolver": "^7.0",
    // "symfony/config": "^7.0"
  },
  "autoload": {
    "psr-4": {
      "Glueful\\Config\\": "src/Config/",
      "Glueful\\Validation\\": "src/Validation/"
    }
  }
}
```

---

## Testing Strategy

- **Unit tests** for `ConfigValidator` (required, defaults, type, enum, min/max, nested schema).
- **Rule tests**: Required, Type, Length, Range, Regex, Email, Url, InArray, DateTimeFormat, DbUnique/DbExists (PDO mocked).
- **HTTP tests**: A controller action that triggers validation → assert 422 envelope shape.
- **Smoke test**: Replace a current OptionsResolver site and a TreeBuilder site; assert behavior parity.

Example (PHPUnit, rule table test):
```php
/**
 * @test
 * @dataProvider emailProvider
 */
public function email_rule_works($input, $expectedError) { ... }
```

---

## Rollback Plan

- `git revert` migration commits; `composer require symfony/options-resolver symfony/config`.
- Keep DTOs and schemas; they’re additive and harmless even if Symfony is restored temporarily.

---

## Ship Checklist

**Config**
- [ ] `ConfigValidatorInterface` + `ConfigValidator`
- [ ] Schema files added and used
- [ ] DTOs hydrated and container-bound
- [ ] Remove `symfony/config`

**OptionsResolver**
- [ ] All 7 call sites replaced
- [ ] Remove `symfony/options-resolver`

**Validation**
- [ ] Contracts + Validator + Rules implemented
- [ ] `ValidationException` and HTTP middleware registered
- [ ] Controller/request sites updated to throw on invalid input
- [ ] Tests green (rules + HTTP mapping)

**Docs**
- [ ] Developer guide updated (how to define schemas, use DTOs, add validation rules)

---

## Validation Usage Analysis: Surprisingly Limited!

Here's the **actual usage** of the Validation system:

### 📊 Usage Statistics

**Total files referencing Validation: 61**
- **33 files** are within `/Validation/` directory itself (constraints, validators, etc.)
- **Only 28 files** outside of `/Validation/` actually use it

### 🎯 Key Usage Points

1. **Controllers (3 files)**
   - `BaseController.php` - Handles `ValidationException`
   - `AuthController.php` - Throws `ValidationException` for auth errors
   - `ConfigController.php` - Throws `ValidationException` for config errors

2. **DTOs (5 files)**
   - `UserDTO.php` - Uses validation attributes
   - `EmailDTO.php` - Email validation
   - `PasswordDTO.php` - Password validation
   - `UsernameDTO.php` - Username validation
   - `ListResourceRequestDTO.php` - Request validation

3. **Services (2-3 files)**
   - `AuthenticationService.php` - Some validation logic
   - `UserRepository.php` - Database validation
   - `ValidatorServiceProvider.php` - DI registration

4. **Exception Handling**
   - Custom `ValidationException` (not Symfony's)
   - Used for manual validation errors, not attribute-based

### 🔍 Critical Finding

**Most "validation" is actually manual `ValidationException` throwing**, not Symfony Validator usage:

```php
// This is the pattern in controllers
throw new ValidationException('Email address is required');
```

**Only the DTOs use actual validation attributes**:
```php
#[Required]
#[Email(message: 'Please provide a valid email address')]
public string $email;
```

### ✅ Migration Impact: LOW

**Why it's easier than expected:**

1. **Limited Attribute Usage** - Only 5 DTOs use validation attributes
2. **Manual Validation** - Most validation is manual exception throwing
3. **Custom Exception** - Already using Glueful's `ValidationException`, not Symfony's
4. **No Complex Rules** - Basic rules: Required, Email, StringLength, Choice
5. **No Nested Validation** - No complex object graphs being validated

### 🎯 Recommended Migration Path

**Immediate (1 day):**
1. **Replace the 5 DTOs** with new validation rules
2. **Update ValidatorServiceProvider** to use new system
3. **Keep ValidationException** as-is (already custom)

**The migration plan's lightweight validator can handle this easily:**
- Required ✅
- Email ✅
- StringLength → Length ✅
- Choice → InArray ✅

### 💡 Surprising Conclusion

Despite having 20+ custom constraint classes in `/Validation/`, **they're barely used!** The actual validation footprint is:
- 5 DTOs with basic attributes
- Manual exception throwing in controllers
- Database validators (Unique, Exists) - only 2 uses

**You can safely remove `symfony/validator`** with minimal effort. The migration plan's simple validation system is more than sufficient for your actual usage.

---

## Risks & Mitigations

- **Hidden attribute usage**: add CI grep for `Symfony\\Component\\Validator\\Constraints` and `#[`. Fail if present.
- **Behavior drift**: document `Required` semantics vs prior `NotBlank`; update tests accordingly.
- **DB rule performance**: ensure proper indexes; queries use LIMIT 1.

## Nice polish (optional)

- Add a `Coerce` helper for int/bool from request arrays.
- Provide a `Rules::of(...)` sugar to tidy Validator setup.

## Notes & Future Enhancements

- Add attribute support for validation on DTO properties later (nice DX, optional).
- Add i18n message catalog for rule messages.
- Consider a tiny "coercion" layer for booleans/ints from strings when reading env/config.
- If nested schemas grow complex, add dot‑path selectors (`users.*.email`) for brevity.
