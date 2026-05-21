# N+1 Query Detection

Glueful's ORM ships with an N+1 detector that fires at the model layer with enough context to give actionable warnings — `User::profile lazy-loaded from a collection of 50, add ->with('profile')`. It is opt-in via configuration, dev-only by default, and supports a strict mode that throws for CI enforcement.

## Overview

When you load a collection of models (e.g., `User::query($context)->get()`) and then access a relation on one of them without having eager-loaded it, that's the classic N+1 pattern. The framework tracks which models came from a collection and warns or throws when those models lazy-load a relation.

> Glueful's ORM requires an `ApplicationContext` on `Model::query()`. In controllers it's typically `$this->context`; in services, inject it. The examples below show the pattern.

**Does trigger detection:**

- `User::query($context)->get()` → `$user->posts`
- `User::query($context)->with('posts')->get()` → `$user->posts[0]->comments`

**Does not trigger:**

- `User::query($context)->find(1)->posts` (single-row find)
- `User::query($context)->with('posts')->get()` → `$user->posts` (already loaded)
- `$user->load('posts')->posts` (explicit load)
- `new User([...], $context)->posts` (manually constructed, not hydrated)

## Modes

| Mode | Behavior |
|---|---|
| `off` | Detection disabled. Minimal overhead (one static read per query). |
| `warn` | Log `[GLUEFUL-N+1] ...` via `error_log()`. Deduped per request — same `(model, relation)` pair warns once. |
| `strict` | Throw `LazyLoadingViolationException` (extends `\LogicException`). |
| `auto` | Resolves to `warn` in development, `off` otherwise. |

## Configuration

```env
DB_LAZY_LOADING_MODE=auto
```

Or in `config/database.php`:

```php
'orm' => [
    'lazy_loading_mode' => env('DB_LAZY_LOADING_MODE', 'auto'),
],
```

## Per-model opt-out

Some legacy models intentionally lazy-load. Opt out per-class:

```php
class LegacyUser extends Model
{
    protected ?string $instanceLazyLoadingMode = 'off';
}
```

The per-model override beats the global setting.

## Custom violation handler

Replace the default warn/throw behavior with your own handler — e.g., to route through PSR logger, dispatch an event, or capture to Sentry:

```php
use Glueful\Database\ORM\Model;
use Glueful\Bootstrap\ApplicationContext;
use Psr\Log\LoggerInterface;

// In a service provider's boot() method, with $context available:
Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) use ($context) {
    $logger = app($context, LoggerInterface::class);
    $logger->warning("N+1: {$model::class}::$relation");

    if ($context->getEnvironment() === 'testing') {
        throw new \LogicException("N+1 detected in test");
    }
});
```

The callback **replaces** default behavior — neither `error_log()` nor the strict-mode throw fires automatically. Pass `null` to clear the callback.

## CI enforcement

Enable strict mode in tests so any N+1 fails CI:

```php
use Glueful\Tests\Support\Traits\ResetsLazyLoading;

class MyControllerTest extends TestCase
{
    use ResetsLazyLoading;

    protected function setUp(): void
    {
        parent::setUp();
        Model::preventLazyLoading('strict');
    }

    public function testIndex(): void
    {
        // If the controller does foreach($users as $u) { $u->posts; },
        // the test fails with LazyLoadingViolationException.
        $this->get('/users');
    }
}
```

## Coexistence with the SQL-pattern detectors

`DevelopmentQueryMonitor` and `QueryLogger::detectN1Patterns()` (the existing SQL-pattern detectors) continue to work. They fire later in the request lifecycle and detect *patterns* in raw SQL, which catches some cases this detector misses (and vice versa). All three can fire on the same query.

The new ORM-aware detector's advantage is *context*: it tells you the model and relation, not just that "10 similar queries ran."

## Performance

`off` mode skips hydration tagging and violation handling. The only cost is one static property read per query — not zero, but indistinguishable from noise.

## Long-running runtimes

Under PHP-FPM and CLI, PHP's request shutdown automatically clears the dedupe set between requests. Under long-running runtimes (Swoole, RoadRunner, FrankenPHP via `glueful/runiva`), state persists across requests. Call `Model::clearLazyLoadingWarnings()` at request boundaries — for example, from a Runiva middleware.
