# Scaffold Commands Enhancement Implementation Plan

> A comprehensive plan for extending the scaffold command system with additional generators for middleware, events, listeners, jobs, rules, and tests.

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Goals and Non-Goals](#goals-and-non-goals)
3. [Current State Analysis](#current-state-analysis)
4. [Architecture Design](#architecture-design)
5. [Command Specifications](#command-specifications)
6. [Stub Templates](#stub-templates)
7. [Implementation Phases](#implementation-phases)
8. [Testing Strategy](#testing-strategy)
9. [API Reference](#api-reference)

---

## Executive Summary

This document outlines the extension of Glueful's scaffold command system. Building on the existing `scaffold:model`, `scaffold:controller`, `scaffold:request`, and `scaffold:resource` commands, we will add generators for:

- **`scaffold:middleware`** - Generate route middleware classes
- **`scaffold:job`** - Generate queue job classes
- **`scaffold:rule`** - Generate validation rule classes
- **`scaffold:test`** - Generate test classes (unit/feature)

> **Already Implemented:**
> - **`event:create`** - Generate event classes (equivalent to `scaffold:event`)
> - **`event:listener`** - Generate event listener classes (equivalent to `scaffold:listener`)

All commands follow the established patterns and integrate with the framework's architecture.

---

## Goals and Non-Goals

### Goals

- ✅ Consistent command interface across all scaffold commands
- ✅ Customizable stub templates
- ✅ Smart defaults based on existing code patterns
- ✅ IDE-friendly generated code with proper type hints
- ✅ Integration with existing framework components
- ✅ Support for nested namespaces (e.g., `scaffold:middleware Admin/AuthMiddleware`)

### Non-Goals

- ❌ CRUD resource generators (covered by scaffold:controller --resource)
- ❌ GUI-based scaffolding
- ❌ External code analysis for generation
- ❌ Migration generators (handled by scaffold:model --migration)

---

## Current State Analysis

### Existing Scaffold Commands

```
src/Console/Commands/Scaffold/
├── ControllerCommand.php   # scaffold:controller
├── ModelCommand.php        # scaffold:model
├── RequestCommand.php      # scaffold:request
└── ResourceCommand.php     # scaffold:resource

src/Console/Commands/Event/
├── CreateEventCommand.php      # event:create (equivalent to scaffold:event)
└── CreateListenerCommand.php   # event:listener (equivalent to scaffold:listener)
```

> **Note:** Event and listener generation commands already exist under `event:create` and `event:listener`. These are functionally complete and follow the same patterns as scaffold commands. We will document these as the canonical commands rather than duplicating them.

### Existing Command Pattern

All scaffold commands follow this pattern:

```php
#[AsCommand(
    name: 'scaffold:example',
    description: 'Scaffold an example class'
)]
class ExampleCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The class name')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('path', null, InputOption::VALUE_OPTIONAL, 'Custom output path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 1. Parse arguments and options
        // 2. Build class content from stub
        // 3. Write to file system
        // 4. Output success message
    }
}
```

### Key Infrastructure

- `BaseCommand` - Base class with helpers
- `StorageManager` - File system operations
- `PathGuard` - Security for file paths
- Symfony Console `#[AsCommand]` attribute

---

## Architecture Design

### Directory Structure

```
src/
├── Console/
│   └── Commands/
│       ├── Scaffold/
│       │   ├── ControllerCommand.php     # ✅ Existing
│       │   ├── ModelCommand.php          # ✅ Existing
│       │   ├── RequestCommand.php        # ✅ Existing
│       │   ├── ResourceCommand.php       # ✅ Existing
│       │   ├── MiddlewareCommand.php     # 📋 New
│       │   ├── JobCommand.php            # 📋 New
│       │   ├── RuleCommand.php           # 📋 New
│       │   └── TestCommand.php           # 📋 New
│       │
│       └── Event/
│           ├── CreateEventCommand.php    # ✅ Existing (event:create)
│           └── CreateListenerCommand.php # ✅ Existing (event:listener)
│
└── stubs/                                # Template stubs
    ├── middleware.stub
    ├── middleware.route.stub
    ├── job.stub
    ├── rule.stub
    ├── test.unit.stub
    └── test.feature.stub
```

### Generated File Locations

| Command | Default Output Path |
|---------|---------------------|
| `scaffold:middleware` | `app/Http/Middleware/` |
| `scaffold:event` | `app/Events/` |
| `scaffold:listener` | `app/Listeners/` |
| `scaffold:job` | `app/Jobs/` |
| `scaffold:rule` | `app/Validation/Rules/` |
| `scaffold:test` | `tests/Unit/` or `tests/Feature/` |

---

## Command Specifications

### scaffold:middleware

Generate middleware classes implementing `RouteMiddleware` interface.

```bash
# Basic usage
php glueful scaffold:middleware RateLimitMiddleware

# With nested namespace
php glueful scaffold:middleware Admin/AuthMiddleware

# Force overwrite
php glueful scaffold:middleware CacheMiddleware --force
```

**Options:**
| Option | Short | Description |
|--------|-------|-------------|
| `--force` | `-f` | Overwrite existing file |
| `--path` | - | Custom output directory |

**Generated Code:**

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Glueful\Routing\Middleware\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RateLimitMiddleware
 *
 * @package App\Http\Middleware
 */
class RateLimitMiddleware implements RouteMiddleware
{
    /**
     * Handle the incoming request
     *
     * @param Request $request The incoming request
     * @param callable $next The next middleware in the pipeline
     * @param mixed ...$params Runtime parameters passed to the middleware
     * @return Response
     */
    public function handle(Request $request, callable $next, ...$params): Response
    {
        // Pre-processing logic here

        $response = $next($request);

        // Post-processing logic here

        return $response;
    }
}
```

---

### event:create (✅ Already Implemented)

Generate event classes using the `EventHelpers` trait.

**Location:** `src/Console/Commands/Event/CreateEventCommand.php`

```bash
# Basic usage
php glueful event:create UserCreated

# With type/category (creates in subdirectory)
php glueful event:create LoginFailed --type=auth
# Creates: app/Events/Auth/LoginFailedEvent.php

# Nested namespace
php glueful event:create Auth/LoginFailedEvent
```

**Options:**
| Option | Short | Description |
|--------|-------|-------------|
| `--type` | `-t` | Event category (creates subdirectory) |

---

### event:listener (✅ Already Implemented)

Generate event listener classes.

**Location:** `src/Console/Commands/Event/CreateListenerCommand.php`

```bash
# Basic usage
php glueful event:listener SendWelcomeEmail

# With event type
php glueful event:listener SendWelcomeEmail --event=App\\Events\\UserCreatedEvent

# Custom method name
php glueful event:listener SecurityAudit --method=onSecurityEvent
```

**Options:**
| Option | Short | Description |
|--------|-------|-------------|
| `--event` | `-e` | The fully-qualified event class to handle |
| `--method` | `-m` | Handler method name (default: `handle`) |

---

### scaffold:job

Generate queue job classes.

```bash
# Basic usage
php glueful scaffold:job ProcessPayment

# With queue specification
php glueful scaffold:job SendNewsletter --queue=emails

# With retry options
php glueful scaffold:job ImportData --tries=3 --backoff=60

# Unique job
php glueful scaffold:job GenerateReport --unique
```

**Options:**
| Option | Short | Description |
|--------|-------|-------------|
| `--queue` | `-q` | The queue name |
| `--tries` | - | Number of retry attempts (default: 3) |
| `--backoff` | - | Seconds to wait before retry |
| `--unique` | `-u` | Make the job unique |
| `--force` | `-f` | Overwrite existing file |
| `--path` | - | Custom output directory |

**Generated Code:**

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use Glueful\Queue\Job;

/**
 * ProcessPayment Job
 *
 * @package App\Jobs
 */
class ProcessPayment extends Job
{
    /**
     * The queue this job should run on
     */
    public string $queue = 'default';

    /**
     * The number of times the job may be attempted
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying
     */
    public int $backoff = 60;

    /**
     * Create a new job instance
     *
     * @param array<string, mixed> $data Job data
     */
    public function __construct(
        protected array $data = []
    ) {
        //
    }

    /**
     * Execute the job
     *
     * @return void
     */
    public function handle(): void
    {
        // Process the job
    }

    /**
     * Handle a job failure
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        // Handle failure - log, notify, etc.
    }
}
```

---

### scaffold:rule

Generate custom validation rule classes.

```bash
# Basic usage
php glueful scaffold:rule UniqueEmail

# With parameters
php glueful scaffold:rule PasswordStrength --params=minLength,requireNumbers

# Implicit rule (validates even when field is empty)
php glueful scaffold:rule RequiredWithoutField --implicit
```

**Options:**
| Option | Short | Description |
|--------|-------|-------------|
| `--params` | `-p` | Comma-separated constructor parameters |
| `--implicit` | `-i` | Make the rule implicit |
| `--force` | `-f` | Overwrite existing file |
| `--path` | - | Custom output directory |

**Generated Code:**

```php
<?php

declare(strict_types=1);

namespace App\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

/**
 * UniqueEmail Validation Rule
 *
 * @package App\Validation\Rules
 */
class UniqueEmail implements Rule
{
    /**
     * Create a new rule instance
     */
    public function __construct()
    {
        //
    }

    /**
     * Validate the given value
     *
     * @param mixed $value The value to validate
     * @param array<string, mixed> $context Validation context (field, data, etc.)
     * @return string|null Error message or null if valid
     */
    public function validate(mixed $value, array $context = []): ?string
    {
        $field = $context['field'] ?? 'field';

        // Implement validation logic
        // Return null if valid, or an error message if invalid

        if (/* validation fails */) {
            return "The {$field} is not valid.";
        }

        return null;
    }
}
```

**With Parameters:**

```php
<?php

declare(strict_types=1);

namespace App\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

/**
 * PasswordStrength Validation Rule
 *
 * @package App\Validation\Rules
 */
class PasswordStrength implements Rule
{
    /**
     * Create a new rule instance
     */
    public function __construct(
        private int $minLength = 8,
        private bool $requireNumbers = true
    ) {
        //
    }

    /**
     * Validate the given value
     *
     * @param mixed $value The value to validate
     * @param array<string, mixed> $context Validation context
     * @return string|null Error message or null if valid
     */
    public function validate(mixed $value, array $context = []): ?string
    {
        $field = $context['field'] ?? 'password';

        if (strlen((string) $value) < $this->minLength) {
            return "The {$field} must be at least {$this->minLength} characters.";
        }

        if ($this->requireNumbers && !preg_match('/[0-9]/', (string) $value)) {
            return "The {$field} must contain at least one number.";
        }

        return null;
    }
}
```

---

### scaffold:test

Generate test classes for unit or feature testing.

```bash
# Unit test (default)
php glueful scaffold:test UserServiceTest

# Feature test
php glueful scaffold:test UserApiTest --feature

# Unit test for specific class
php glueful scaffold:test UserServiceTest --unit --class=App\\Services\\UserService

# With test methods
php glueful scaffold:test PaymentTest --methods=testCharge,testRefund,testCancel
```

**Options:**
| Option | Short | Description |
|--------|-------|-------------|
| `--unit` | `-u` | Generate unit test (default) |
| `--feature` | `-f` | Generate feature/integration test |
| `--class` | `-c` | The class being tested |
| `--methods` | `-m` | Comma-separated test methods |
| `--force` | - | Overwrite existing file |
| `--path` | - | Custom output directory |

**Generated Code (Unit Test):**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * UserServiceTest
 *
 * @package App\Tests\Unit
 */
class UserServiceTest extends TestCase
{
    /**
     * Set up test fixtures
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Set up test fixtures
    }

    /**
     * Tear down test fixtures
     */
    protected function tearDown(): void
    {
        // Clean up

        parent::tearDown();
    }

    /**
     * Test example
     */
    public function testExample(): void
    {
        $this->assertTrue(true);
    }
}
```

**Generated Code (Feature Test):**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use Glueful\Testing\TestCase;

/**
 * UserApiTest
 *
 * @package App\Tests\Feature
 */
class UserApiTest extends TestCase
{
    /**
     * Set up test fixtures
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Set up test fixtures
    }

    /**
     * Test example endpoint
     */
    public function testExample(): void
    {
        // Make HTTP request
        // $response = $this->get('/api/users');

        // Assert response
        $this->assertTrue(true);
    }
}
```

---

## Stub Templates

### Stub File Format

Stubs use placeholder syntax: `{{variable}}`

```php
<?php

declare(strict_types=1);

namespace {{namespace}};

{{imports}}

/**
 * {{className}}
 *
 * @package {{namespace}}
 */
class {{className}} {{extends}} {{implements}}
{
    {{content}}
}
```

### Stub Locations

1. **Framework stubs**: `vendor/glueful/framework/stubs/`
2. **Application stubs** (override): `stubs/`

### Customizing Stubs

Users can publish and customize stubs:

```bash
php glueful stubs:publish

# Creates stubs/ directory with all default stubs
# Users can modify these to customize generated code
```

---

## Implementation Phases

### Phase 1: Core Commands (Week 1)

**Deliverables:**
- [ ] `scaffold:middleware` command
- [x] `event:create` command (✅ Already implemented)
- [x] `event:listener` command (✅ Already implemented)
- [ ] Basic stub templates

**Acceptance Criteria:**
```bash
php glueful scaffold:middleware AuthMiddleware
# Creates app/Http/Middleware/AuthMiddleware.php

# Already working:
php glueful event:create UserCreated --type=auth
# Creates app/Events/Auth/UserCreatedEvent.php

php glueful event:listener SendWelcomeEmail --event=App\\Events\\UserCreatedEvent
# Creates app/Events/Listeners/SendWelcomeEmailListener.php
```

### Phase 2: Job & Rule Commands (Week 2)

**Deliverables:**
- [ ] `scaffold:job` command
- [ ] `scaffold:rule` command
- [ ] Queue integration for listeners
- [ ] Extended stub options

**Acceptance Criteria:**
```bash
php glueful scaffold:job ProcessPayment --queue=payments --tries=5
# Creates app/Jobs/ProcessPayment.php

php glueful scaffold:rule UniqueEmail
# Creates app/Validation/Rules/UniqueEmail.php
```

### Phase 3: Test Commands & Polish (Week 3)

**Deliverables:**
- [ ] `scaffold:test` command
- [ ] `stubs:publish` command
- [ ] Complete documentation
- [ ] Test coverage for all commands

**Acceptance Criteria:**
```bash
php glueful scaffold:test UserServiceTest --unit
# Creates tests/Unit/UserServiceTest.php

php glueful scaffold:test UserApiTest --feature
# Creates tests/Feature/UserApiTest.php

php glueful stubs:publish
# Creates stubs/ directory with all stub files
```

---

## Testing Strategy

### Unit Tests

```php
<?php

namespace Glueful\Tests\Unit\Console\Commands\Scaffold;

use PHPUnit\Framework\TestCase;
use Glueful\Console\Commands\Scaffold\MiddlewareCommand;

class MiddlewareCommandTest extends TestCase
{
    public function testGeneratesMiddlewareClass(): void
    {
        // Test command generates correct file content
    }

    public function testHandlesNestedNamespaces(): void
    {
        // Test Admin/AuthMiddleware creates correct namespace
    }

    public function testRespectsForceOption(): void
    {
        // Test --force overwrites existing files
    }
}
```

### Integration Tests

```php
<?php

namespace Glueful\Tests\Integration\Console\Commands\Scaffold;

use Glueful\Tests\TestCase;

class ScaffoldCommandsTest extends TestCase
{
    public function testMiddlewareCommandCreatesFile(): void
    {
        $this->artisan('scaffold:middleware', ['name' => 'TestMiddleware'])
            ->assertExitCode(0);

        $this->assertFileExists('app/Http/Middleware/TestMiddleware.php');
    }

    public function testGeneratedMiddlewareCompiles(): void
    {
        $this->artisan('scaffold:middleware', ['name' => 'TestMiddleware']);

        // Attempt to load the class
        require_once 'app/Http/Middleware/TestMiddleware.php';

        $this->assertTrue(class_exists('App\\Http\\Middleware\\TestMiddleware'));
    }
}
```

---

## API Reference

### Command Summary

| Command | Description | Key Options |
|---------|-------------|-------------|
| `scaffold:middleware <name>` | Generate middleware class | `--force`, `--path` |
| `event:create <name>` | Generate event class ✅ | `--type` |
| `event:listener <name>` | Generate listener class ✅ | `--event`, `--method` |
| `scaffold:job <name>` | Generate queue job class | `--queue`, `--tries`, `--unique` |
| `scaffold:rule <name>` | Generate validation rule | `--params`, `--implicit` |
| `scaffold:test <name>` | Generate test class | `--unit`, `--feature`, `--methods` |

> ✅ = Already implemented

### Common Options

All scaffold commands support these options:

| Option | Description |
|--------|-------------|
| `--force` / `-f` | Overwrite existing files without confirmation |
| `--path` | Custom output directory (relative to app root) |

### Exit Codes

| Code | Meaning |
|------|---------|
| 0 | Success |
| 1 | File already exists (without --force) |
| 2 | Invalid argument or option |
| 3 | File system error |

---

## Migration Notes

- All new commands follow established patterns from `scaffold:model`
- No breaking changes to existing commands
- Stub customization is opt-in via `stubs:publish`
