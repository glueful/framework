# BaseRepository `$sharedConnection` — lifecycle debt

**Status:** open / captured, not scheduled
**Area:** `src/Repository/BaseRepository.php`
**Origin:** surfaced while fixing a Thallo multi-tenancy two-boot test-harness bug (a dropped
throwaway DB left a dead process-global connection that the next boot reused →
`PDOException: no connection to the server`).

## The static

`BaseRepository` memoises **one** `Connection` for the whole process and never resets it between
framework boots or across a long-running worker's lifetime:

```php
private static ?Connection $sharedConnection = null;

protected static function getSharedConnection(?ApplicationContext $context = null): Connection
{
    if (self::$sharedConnection === null) {
        self::$sharedConnection = Connection::fromContext($context);
        return self::$sharedConnection;
    }
    if ($context !== null && self::$sharedConnection->hasContext() === false) {
        self::$sharedConnection = Connection::fromContext($context);
    }
    return self::$sharedConnection;
}

public function __construct(?Connection $connection = null, ?ApplicationContext $context = null)
{
    $this->context = $context;
    if ($connection !== null) {
        self::$sharedConnection = $connection;
    }
    $this->db = self::getSharedDb($context);
}
```

Its whole lifecycle is "born on the first repository, lives until the process dies." Nothing in the
framework ever resets or invalidates it.

## Why it exists (it is load-bearing, not an accident)

Measured across framework + extensions + a dependent app:

| Signal | Count |
|---|---|
| Classes extending `BaseRepository` | 59 |
| `new *Repository(...)` call sites | 519 |
| …of those, **zero-arg** `new XRepository()` (rely entirely on the static) | 264 |
| Subclasses defining their own `__construct` | 32 |
| Repositories registered as container services | 0 |

The static is what makes `new XRepository()` "just work" with no args and no container in hand. There
is **no DI path for repositories today** — they are hand-instantiated everywhere.

## Impact

- **Request-per-process (PHP-FPM / CLI):** benign. The process exits, the static dies with it.
- **Tests:** real. A memoised connection outlives the DB/context it was built for. The Thallo retrofit
  harness currently works around this with reflection
  (`RetrofitHarnessTestCase::resetSharedRepositoryConnection()`), nulling the static in
  `setUpBeforeClass`/`tearDownAfterClass`. Reflection because there is no public reset seam.
- **Long-running workers (`QueueWorker`, `SchedulerCommand`, `WorkCommand` — `while(true)` loops):**
  latent. If the shared PDO goes stale or the server drops it, there is no seam to force a rebuild; the
  worker keeps handing out a dead connection (a milder cousin of the test failure above).

## Fix ladder (cheap → expensive)

### (a) Reflection in the test harness — DONE
Cost: ~0. Test-only. Already in place in the Thallo retrofit harness. Not a framework change.

### (b) Public reset seam — SMALL, recommended
Add a public method so tests (and workers) can force a rebuild without reflection:

```php
/**
 * Drop the process-global shared connection so the next repository rebuilds it from its own
 * live context. For test isolation (a boot whose DB was dropped) and long-running workers that
 * need to shed a stale/terminated connection between jobs.
 */
public static function resetSharedConnection(): void
{
    self::$sharedConnection = null;
}
```

- ~10 lines + one unit test.
- Replaces the harness reflection with a supported call.
- Gives workers a lifecycle seam they lack today.
- Does **not** touch any of the 519 call sites.

Follow-up: swap `RetrofitHarnessTestCase::resetSharedRepositoryConnection()` (reflection) to call this.

### (c) Key the memo by context/connection instead of a single global — MEDIUM
Replace the single `?Connection` with a small map keyed by context/connection identity, so a second
boot with a different context gets its own connection instead of inheriting the first. Kills the
cross-boot-leak *class* of bug without rewriting call sites — but it is still a mutable process-static.

### (d) Remove the static; resolve `Connection` per scope from the container — LARGE
The "right" architecture, but a deliberate, phased initiative — not a task:

1. Register all 59 repositories as container services **or** build a repository factory/resolver.
2. Rewrite the ~264 zero-arg `new XRepository()` sites (audit the other 255) to resolve via the
   container/factory.
3. Update the 32 subclass constructors.
4. Solve the hard cases: repos built inside helpers, static methods, and other repos — code holding no
   container reference.
5. Design the real per-scope lifecycle (fresh/validated connection per request or per worker job).
6. Regression-test across framework + every extension + dependent apps.

Multi-day, cross-repo, real breakage risk in the data-access layer.

## Recommendation

Ship **(b)** next time the framework is open; consider **(c)** only if worker connection-staleness
actually bites; log **(d)** as a roadmap item and do not let its elegance pull it into unrelated work.
Until **(b)** lands, the harness reflection is the accepted workaround.
