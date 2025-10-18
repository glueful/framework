<?php

declare(strict_types=1);

namespace Glueful\Async;

use Glueful\Async\Contracts\Task;
use Glueful\Async\Task\CompletedTask;
use Glueful\Async\Task\FailedTask;
use Glueful\Async\Task\FiberTask;

/**
 * Lightweight Promise-style wrapper around async Tasks for ergonomic chaining.
 *
 * Promise provides a familiar JavaScript-like Promise API for composing async
 * operations in PHP. Unlike traditional PHP promises, this wrapper is built on
 * top of the Task system and leverages PHP fibers for true cooperative async
 * execution without blocking.
 *
 * Key Characteristics:
 * - Wraps Task instances with a chainable API
 * - Supports then/catch/finally composition patterns
 * - Automatic unwrapping of nested Promises and Tasks
 * - Preserves original Task execution model (no external event loops)
 * - Compatible with FiberScheduler for concurrent execution
 *
 * Execution Model:
 * - Promises are lazy - callbacks don't execute until await() is called
 * - Each chain method creates a new FiberTask with the callback
 * - The scheduler executes tasks when await() or all()/race() is invoked
 * - Errors propagate through the chain until caught
 *
 * Interop Rules for Callback Return Values:
 * - If callback returns a Promise, its underlying Task is awaited
 * - If callback returns a Task, that Task is awaited
 * - Otherwise, the raw value is returned as-is
 *
 * Usage Examples:
 * ```php
 * // Example 1: Basic chaining with then/catch
 * $promise = Promise::resolve(42)
 *     ->then(fn($x) => $x * 2)
 *     ->then(fn($x) => "Result: $x")
 *     ->catch(fn($e) => "Error: " . $e->getMessage());
 *
 * echo $promise->await(); // "Result: 84"
 *
 * // Example 2: Async HTTP request with error handling
 * $httpClient = new CurlMultiHttpClient();
 * $request = new Request('GET', 'https://api.example.com/users');
 *
 * $promise = Promise::fromTask($httpClient->sendAsync($request))
 *     ->then(fn($response) => json_decode($response->getBody(), true))
 *     ->then(fn($data) => array_map(fn($user) => $user['name'], $data))
 *     ->catch(fn($e) => [])  // Return empty array on error
 *     ->finally(fn() => logger()->info('Request completed'));
 *
 * $userNames = $promise->await();
 *
 * // Example 3: Concurrent requests with Promise::all()
 * $requests = [
 *     Promise::fromTask($httpClient->sendAsync(new Request('GET', '/users'))),
 *     Promise::fromTask($httpClient->sendAsync(new Request('GET', '/posts'))),
 *     Promise::fromTask($httpClient->sendAsync(new Request('GET', '/comments'))),
 * ];
 *
 * [$users, $posts, $comments] = Promise::all($requests)->await();
 *
 * // Example 4: Racing multiple data sources
 * $sources = [
 *     Promise::fromTask($redisCache->getAsync('user:123')),
 *     Promise::fromTask($database->queryAsync('SELECT * FROM users WHERE id = 123')),
 * ];
 *
 * // Use whichever source responds first
 * $userData = Promise::race($sources)->await();
 *
 * // Example 5: Nested promise chaining
 * $promise = Promise::resolve('user:123')
 *     ->then(function($userId) use ($cache) {
 *         // Return another Promise - automatically unwrapped
 *         return Promise::fromTask($cache->getAsync($userId));
 *     })
 *     ->then(function($user) use ($db) {
 *         // Chain another async operation
 *         return Promise::fromTask($db->getPosts($user['id']));
 *     })
 *     ->catch(fn($e) => []);  // Fallback to empty array
 *
 * $posts = $promise->await();
 *
 * // Example 6: Error recovery in chain
 * $promise = Promise::resolve('https://api.example.com/data')
 *     ->then(fn($url) => $httpClient->sendAsync(new Request('GET', $url)))
 *     ->then(fn($response) => json_decode($response->getBody(), true))
 *     ->catch(function($e) use ($cache) {
 *         // On error, try cache fallback
 *         logger()->warning('API failed, using cache', ['error' => $e->getMessage()]);
 *         return $cache->get('api_data_fallback');
 *     })
 *     ->then(fn($data) => processData($data));
 *
 * $result = $promise->await();
 * ```
 *
 * Best Practices:
 * - Always add catch() handlers to prevent unhandled rejections
 * - Use finally() for cleanup that must happen regardless of outcome
 * - Prefer Promise::all() over sequential awaits for concurrent operations
 * - Use Promise::race() for timeout patterns or redundant data sources
 * - Keep callback functions pure and side-effect free when possible
 * - Avoid blocking operations inside promise callbacks
 *
 * Performance Notes:
 * - Each then/catch/finally creates a new FiberTask (small overhead)
 * - Long chains are efficient - callbacks only execute when awaited
 * - Promise::all() executes tasks concurrently via scheduler
 * - Promise::race() returns as soon as first task succeeds
 *
 * vs Traditional PHP Promises:
 * - No external event loop required (uses FiberScheduler)
 * - True non-blocking async (not just callback registration)
 * - Integrates with Task system for flexibility
 * - Simpler mental model (familiar JavaScript semantics)
 *
 * @see Task For the underlying async execution primitive
 * @see FiberTask For fiber-based task implementation
 * @see FiberScheduler For concurrent task execution
 */
final class Promise
{
    /**
     * Creates a new Promise wrapping a Task.
     *
     * You typically don't call this constructor directly - instead use
     * Promise::resolve(), Promise::fromTask(), or chaining methods.
     *
     * @param Task $task The underlying task to wrap
     */
    public function __construct(private Task $task)
    {
    }

    /**
     * Get the underlying Task instance.
     *
     * This allows you to access the raw Task if you need to pass it to
     * scheduler methods or other Task-compatible APIs.
     *
     * @return Task The wrapped task instance
     *
     * Example:
     * ```php
     * $promise = Promise::resolve(42);
     * $task = $promise->task();
     * $scheduler->all([$task]);
     * ```
     */
    public function task(): Task
    {
        return $this->task;
    }

    /**
     * Await the promise and return its result (or throw on failure).
     *
     * This is the terminal operation that actually executes the promise chain.
     * It blocks until the underlying task completes, then returns the final
     * result or throws any exception that occurred during execution.
     *
     * Behavior:
     * - Executes all chained callbacks in order
     * - Blocks until the final result is available
     * - Throws if any callback threw and wasn't caught
     * - Returns the final resolved value
     *
     * @return mixed The final result of the promise chain
     * @throws \Throwable If the promise rejected and wasn't caught
     *
     * Examples:
     * ```php
     * // Example 1: Simple await
     * $result = Promise::resolve(42)->await();  // Returns 42
     *
     * // Example 2: Await with chain
     * $result = Promise::resolve(10)
     *     ->then(fn($x) => $x * 2)
     *     ->await();  // Returns 20
     *
     * // Example 3: Await with error
     * try {
     *     $result = Promise::reject(new \RuntimeException('Failed'))
     *         ->await();
     * } catch (\RuntimeException $e) {
     *     echo $e->getMessage();  // "Failed"
     * }
     *
     * // Example 4: Await with error handling in chain
     * $result = Promise::reject(new \RuntimeException('Failed'))
     *     ->catch(fn($e) => 'default')
     *     ->await();  // Returns "default"
     * ```
     */
    public function await(): mixed
    {
        return $this->task->getResult();
    }

    /**
     * Chain a success callback that transforms the promise's resolved value.
     *
     * The `then()` method creates a new Promise that resolves to the return value
     * of the callback. This allows you to transform values and chain multiple
     * async operations in a readable, linear fashion.
     *
     * Callback Behavior:
     * - Only executes if the previous promise resolves successfully
     * - Receives the resolved value as its parameter
     * - Can return a plain value, Task, or another Promise
     * - Returned Promises/Tasks are automatically unwrapped
     * - If callback throws, the new promise is rejected
     *
     * Return Value Handling:
     * - Plain value → Wrapped in CompletedTask
     * - Task → Awaited, result becomes new promise value
     * - Promise → Unwrapped, its result becomes new promise value
     *
     * @param callable(mixed): (mixed|Task|self) $onFulfilled Callback to transform the value
     * @return self A new Promise that resolves to the callback's return value
     *
     * Examples:
     * ```php
     * // Example 1: Simple value transformation
     * $promise = Promise::resolve(5)
     *     ->then(fn($x) => $x * 2)       // 10
     *     ->then(fn($x) => $x + 3)       // 13
     *     ->then(fn($x) => "Result: $x"); // "Result: 13"
     *
     * echo $promise->await();
     *
     * // Example 2: Chaining async operations
     * $promise = Promise::resolve(123)
     *     ->then(function($userId) use ($db) {
     *         // Return a Task - automatically awaited
     *         return $db->getUserAsync($userId);
     *     })
     *     ->then(function($user) use ($db) {
     *         // Chain another async operation
     *         return $db->getPostsAsync($user['id']);
     *     });
     *
     * $posts = $promise->await();
     *
     * // Example 3: Returning another Promise
     * $promise = Promise::resolve('user:123')
     *     ->then(function($key) use ($cache) {
     *         // Return Promise - automatically unwrapped
     *         return Promise::fromTask($cache->getAsync($key));
     *     })
     *     ->then(fn($user) => $user['name']);
     *
     * $userName = $promise->await();
     *
     * // Example 4: Error handling - then() is skipped on rejection
     * $promise = Promise::reject(new \RuntimeException('Failed'))
     *     ->then(fn($x) => $x * 2)  // Skipped!
     *     ->catch(fn($e) => 0);     // Catches the error
     *
     * $result = $promise->await();  // Returns 0
     * ```
     *
     * Note: Errors thrown in the callback will reject the returned promise.
     */
    public function then(callable $onFulfilled): self
    {
        $parent = $this->task;
        $next = new FiberTask(static function () use ($parent, $onFulfilled) {
            $value = $parent->getResult();
            $res = $onFulfilled($value);
            return Promise::unwrap($res);
        });
        return new self($next);
    }

    /**
     * Chain an error handler that recovers from promise rejections.
     *
     * The `catch()` method creates a new Promise that handles errors from the
     * previous promise in the chain. If no error occurred, the value passes
     * through unchanged. If an error occurred, the callback can recover by
     * returning a fallback value or re-throwing.
     *
     * Callback Behavior:
     * - Only executes if the previous promise was rejected
     * - Receives the exception as its parameter
     * - Can return a recovery value, Task, or Promise
     * - Can re-throw the error or throw a new one
     * - If promise resolved successfully, catch() is skipped
     *
     * Error Recovery:
     * - Return a value → Promise resolves with that value
     * - Return Task/Promise → Awaited, result becomes new value
     * - Throw exception → Promise remains rejected
     *
     * @param callable(\Throwable): (mixed|Task|self) $onRejected Error handler callback
     * @return self A new Promise that has handled the error
     *
     * Examples:
     * ```php
     * // Example 1: Simple error recovery
     * $promise = Promise::reject(new \RuntimeException('Failed'))
     *     ->catch(fn($e) => 'default value');
     *
     * echo $promise->await();  // "default value"
     *
     * // Example 2: Conditional recovery based on error type
     * $promise = Promise::reject(new \RuntimeException('Network error'))
     *     ->catch(function($e) {
     *         if ($e instanceof \RuntimeException) {
     *             return 'recovered';
     *         }
     *         throw $e;  // Re-throw other errors
     *     });
     *
     * echo $promise->await();  // "recovered"
     *
     * // Example 3: Fallback to alternative data source
     * $promise = Promise::fromTask($api->fetchData())
     *     ->catch(function($e) use ($cache) {
     *         logger()->warning('API failed, using cache', ['error' => $e->getMessage()]);
     *         return $cache->get('fallback_data');
     *     });
     *
     * $data = $promise->await();
     *
     * // Example 4: Async fallback operation
     * $promise = Promise::fromTask($primaryDb->query($sql))
     *     ->catch(function($e) use ($replicaDb, $sql) {
     *         // Try replica database on primary failure
     *         return Promise::fromTask($replicaDb->query($sql));
     *     });
     *
     * $result = $promise->await();
     *
     * // Example 5: Error transformation
     * $promise = Promise::fromTask($httpClient->sendAsync($request))
     *     ->catch(function($e) {
     *         // Transform low-level exception to business exception
     *         throw new ServiceUnavailableException('API is down', 0, $e);
     *     });
     *
     * // Example 6: Multiple catch handlers (first match wins)
     * $promise = Promise::fromTask($operation())
     *     ->catch(fn($e) => $e instanceof TimeoutException ? 'timeout' : throw $e)
     *     ->catch(fn($e) => $e instanceof NetworkException ? 'network error' : throw $e)
     *     ->catch(fn($e) => 'unknown error');  // Catch-all
     *
     * $result = $promise->await();
     * ```
     *
     * Note: catch() allows the chain to continue after an error.
     * Without catch(), errors propagate and terminate the chain.
     */
    public function catch(callable $onRejected): self
    {
        $parent = $this->task;
        $next = new FiberTask(static function () use ($parent, $onRejected) {
            try {
                return $parent->getResult();
            } catch (\Throwable $e) {
                $res = $onRejected($e);
                return Promise::unwrap($res);
            }
        });
        return new self($next);
    }

    /**
     * Chain a cleanup callback that runs regardless of success or failure.
     *
     * The `finally()` method creates a new Promise that executes the callback
     * whether the previous promise resolved or rejected. The callback receives
     * no parameters and cannot change the outcome - the original value or error
     * is passed through after the cleanup completes.
     *
     * Callback Behavior:
     * - Executes regardless of whether promise resolved or rejected
     * - Receives no parameters (no access to value or error)
     * - Return value is ignored (cannot change the outcome)
     * - Can perform async cleanup by returning Task/Promise
     * - Original result/error passes through after callback completes
     * - If callback throws, new error replaces original
     *
     * Use Cases:
     * - Resource cleanup (close connections, files, etc.)
     * - Logging completion events
     * - Updating UI state
     * - Releasing locks or semaphores
     * - Recording metrics
     *
     * @param callable(): (void|Task|self) $onFinally Cleanup callback
     * @return self A new Promise with the same outcome as parent
     *
     * Examples:
     * ```php
     * // Example 1: Resource cleanup
     * $promise = Promise::resolve()
     *     ->then(function() use ($connection) {
     *         $connection->begin();
     *         return $connection->query('UPDATE users SET active = 1');
     *     })
     *     ->finally(function() use ($connection) {
     *         $connection->close();  // Always close, regardless of outcome
     *     });
     *
     * $result = $promise->await();
     *
     * // Example 2: Logging completion
     * $promise = Promise::fromTask($api->fetchData())
     *     ->finally(function() {
     *         logger()->info('API request completed');
     *     });
     *
     * // Example 3: Async cleanup
     * $promise = Promise::fromTask($cache->lock('resource'))
     *     ->then(fn() => processResource())
     *     ->finally(function() use ($cache) {
     *         // Async unlock - returns Promise
     *         return Promise::fromTask($cache->unlock('resource'));
     *     });
     *
     * // Example 4: Multiple cleanup steps
     * $promise = Promise::resolve()
     *     ->then(fn() => performOperation())
     *     ->finally(fn() => logger()->info('Operation finished'))
     *     ->finally(fn() => updateMetrics())
     *     ->finally(fn() => releaseResources());
     *
     * // Example 5: Finally with both then and catch
     * $promise = Promise::fromTask($operation())
     *     ->then(fn($result) => processResult($result))
     *     ->catch(fn($e) => handleError($e))
     *     ->finally(fn() => cleanup());  // Runs after then OR catch
     *
     * // Example 6: Finally doesn't change outcome
     * $promise = Promise::resolve(42)
     *     ->finally(fn() => logger()->info('Done'));
     *
     * $result = $promise->await();  // Still returns 42
     *
     * // Example 7: Finally with error still throws
     * try {
     *     Promise::reject(new \RuntimeException('Failed'))
     *         ->finally(fn() => cleanup())
     *         ->await();  // Still throws RuntimeException
     * } catch (\RuntimeException $e) {
     *     echo $e->getMessage();  // "Failed"
     * }
     * ```
     *
     * Note: If the finally callback throws, its error replaces the original
     * outcome. Use try-catch inside finally to prevent this.
     */
    public function finally(callable $onFinally): self
    {
        $parent = $this->task;
        $next = new FiberTask(static function () use ($parent, $onFinally) {
            try {
                $result = $parent->getResult();
            } catch (\Throwable $e) {
                // Run finally, then rethrow
                Promise::unwrap($onFinally());
                throw $e;
            }
            // Success path: run finally, then return value
            Promise::unwrap($onFinally());
            return $result;
        });
        return new self($next);
    }

    /**
     * Create a Promise that immediately resolves to the given value.
     *
     * This is the primary way to create a promise from a plain value. If the
     * value is already a Promise or Task, it's wrapped appropriately without
     * creating redundant wrappers.
     *
     * @param mixed $value The value to resolve to (can be Promise, Task, or any value)
     * @return self A Promise that resolves to the given value
     *
     * Examples:
     * ```php
     * // Example 1: Resolve with plain value
     * $promise = Promise::resolve(42);
     * echo $promise->await();  // 42
     *
     * // Example 2: Resolve with array
     * $promise = Promise::resolve(['name' => 'John', 'age' => 30]);
     * $data = $promise->await();
     *
     * // Example 3: Resolve with existing Promise (returns same promise)
     * $p1 = Promise::resolve(42);
     * $p2 = Promise::resolve($p1);
     * // $p1 === $p2 (same instance)
     *
     * // Example 4: Resolve with Task (wraps it)
     * $task = new CompletedTask(100);
     * $promise = Promise::resolve($task);
     * echo $promise->await();  // 100
     *
     * // Example 5: Start a promise chain
     * $result = Promise::resolve(5)
     *     ->then(fn($x) => $x * 2)
     *     ->then(fn($x) => $x + 3)
     *     ->await();  // 13
     * ```
     */
    public static function resolve(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if ($value instanceof Task) {
            return new self($value);
        }
        return new self(new CompletedTask($value));
    }

    /**
     * Create a Promise that immediately rejects with the given error.
     *
     * This creates a promise in a rejected state, useful for error handling
     * patterns or creating failed promises for testing.
     *
     * @param \Throwable $reason The error to reject with
     * @return self A rejected Promise
     *
     * Examples:
     * ```php
     * // Example 1: Create rejected promise
     * $promise = Promise::reject(new \RuntimeException('Something failed'));
     *
     * try {
     *     $promise->await();
     * } catch (\RuntimeException $e) {
     *     echo $e->getMessage();  // "Something failed"
     * }
     *
     * // Example 2: Recover from rejection
     * $result = Promise::reject(new \RuntimeException('Failed'))
     *     ->catch(fn($e) => 'default value')
     *     ->await();  // "default value"
     *
     * // Example 3: Conditional error handling
     * function loadData(): Promise {
     *     if (!isValid()) {
     *         return Promise::reject(new \InvalidArgumentException('Invalid input'));
     *     }
     *     return Promise::resolve(getData());
     * }
     *
     * // Example 4: Testing error paths
     * $mockService = new class {
     *     public function fetchData(): Promise {
     *         return Promise::reject(new ServiceException('Service down'));
     *     }
     * };
     * ```
     */
    public static function reject(\Throwable $reason): self
    {
        return new self(new FailedTask($reason));
    }

    /**
     * Convert a Task instance to a Promise.
     *
     * This is the bridge between the Task system and Promise API. Use this
     * when you have a Task (e.g., from async HTTP client) and want to use
     * Promise-style chaining.
     *
     * @param Task $task The task to wrap
     * @return self A Promise wrapping the task
     *
     * Examples:
     * ```php
     * // Example 1: Wrap HTTP client task
     * $httpClient = new CurlMultiHttpClient();
     * $task = $httpClient->sendAsync($request);
     * $promise = Promise::fromTask($task);
     *
     * $response = $promise
     *     ->then(fn($r) => $r->getBody())
     *     ->await();
     *
     * // Example 2: Wrap scheduler task
     * $scheduler = new FiberScheduler();
     * $task = $scheduler->spawn(fn() => heavyComputation());
     * $result = Promise::fromTask($task)
     *     ->then(fn($r) => processResult($r))
     *     ->await();
     *
     * // Example 3: Chain multiple async operations
     * $promise = Promise::fromTask($db->getUserAsync(123))
     *     ->then(fn($user) => Promise::fromTask($db->getPostsAsync($user['id'])))
     *     ->then(fn($posts) => array_map(fn($p) => $p['title'], $posts));
     *
     * $titles = $promise->await();
     * ```
     */
    public static function fromTask(Task $task): self
    {
        return new self($task);
    }

    /**
     * Execute all promises/tasks concurrently and resolve to an array of results.
     *
     * Promise::all() runs all promises concurrently using the scheduler and
     * returns a single promise that resolves to an array of all results. The
     * array preserves the original keys from the input array. If any promise
     * rejects, the entire all() promise rejects with that error.
     *
     * Behavior:
     * - Executes all promises concurrently (not sequentially)
     * - Preserves array keys in the result
     * - Rejects if ANY promise rejects
     * - Returns results in same order as input
     * - Uses FiberScheduler for concurrent execution
     *
     * @param array<int|string, Promise|Task> $items Promises or Tasks to execute
     * @return self A Promise that resolves to array of all results
     *
     * Examples:
     * ```php
     * // Example 1: Concurrent HTTP requests
     * $httpClient = new CurlMultiHttpClient();
     * $requests = [
     *     'users' => Promise::fromTask($httpClient->sendAsync(new Request('GET', '/users'))),
     *     'posts' => Promise::fromTask($httpClient->sendAsync(new Request('GET', '/posts'))),
     *     'comments' => Promise::fromTask($httpClient->sendAsync(new Request('GET', '/comments'))),
     * ];
     *
     * $results = Promise::all($requests)->await();
     * // $results = ['users' => Response, 'posts' => Response, 'comments' => Response]
     *
     * // Example 2: Process multiple items concurrently
     * $userIds = [1, 2, 3, 4, 5];
     * $promises = array_map(
     *     fn($id) => Promise::fromTask($db->getUserAsync($id)),
     *     $userIds
     * );
     *
     * $users = Promise::all($promises)->await();
     *
     * // Example 3: With transformation
     * $results = Promise::all([
     *     Promise::resolve(5)->then(fn($x) => $x * 2),
     *     Promise::resolve(10)->then(fn($x) => $x * 2),
     *     Promise::resolve(15)->then(fn($x) => $x * 2),
     * ])->await();
     * // $results = [10, 20, 30]
     *
     * // Example 4: Error handling - one failure rejects all
     * try {
     *     Promise::all([
     *         Promise::resolve(1),
     *         Promise::reject(new \RuntimeException('Failed')),
     *         Promise::resolve(3),
     *     ])->await();
     * } catch (\RuntimeException $e) {
     *     echo $e->getMessage();  // "Failed"
     * }
     *
     * // Example 5: Fetch and process in parallel
     * $dataPromises = Promise::all([
     *     Promise::fromTask($api->fetch('/users')),
     *     Promise::fromTask($api->fetch('/settings')),
     * ])->then(function($results) {
     *     [$users, $settings] = $results;
     *     return mergeData($users, $settings);
     * });
     *
     * $merged = $dataPromises->await();
     *
     * // Example 6: Named keys for clarity
     * $data = Promise::all([
     *     'user' => Promise::fromTask($db->getUser(123)),
     *     'posts' => Promise::fromTask($db->getPosts(123)),
     *     'profile' => Promise::fromTask($db->getProfile(123)),
     * ])->await();
     *
     * processUser($data['user'], $data['posts'], $data['profile']);
     * ```
     *
     * Performance: All promises execute concurrently, so total time ≈ slowest promise.
     */
    public static function all(array $items): self
    {
        $tasks = [];
        foreach ($items as $k => $v) {
            $tasks[$k] = $v instanceof self ? $v->task() : ($v instanceof Task ? $v : new CompletedTask($v));
        }
        $task = new FiberTask(static function () use ($tasks) {
            // Use helper to obtain a scheduler (DI/container if available, else fallback)
            return \scheduler()->all($tasks);
        });
        return new self($task);
    }

    /**
     * Race multiple promises and resolve to the first successful result.
     *
     * Promise::race() executes all promises concurrently and resolves as soon
     * as the first promise succeeds. This is useful for timeout patterns,
     * redundant data sources, or fallback strategies. If all promises fail,
     * the race() promise rejects with the first error encountered.
     *
     * Behavior:
     * - Executes all promises concurrently
     * - Resolves with first successful result
     * - Ignores slower promises after first succeeds
     * - Rejects if ALL promises fail (throws first error)
     * - Useful for timeout patterns and fallbacks
     *
     * @param array<int|string, Promise|Task> $items Promises or Tasks to race
     * @return self A Promise that resolves to the first successful result
     *
     * Examples:
     * ```php
     * // Example 1: Timeout pattern
     * $dataPromise = Promise::fromTask($api->fetchData());
     * $timeoutPromise = Promise::resolve()
     *     ->then(fn() => scheduler()->sleep(5))
     *     ->then(fn() => throw new TimeoutException('Timed out after 5s'));
     *
     * try {
     *     $data = Promise::race([$dataPromise, $timeoutPromise])->await();
     * } catch (TimeoutException $e) {
     *     $data = getCachedData();
     * }
     *
     * // Example 2: Redundant data sources (fastest wins)
     * $data = Promise::race([
     *     Promise::fromTask($primaryDb->query($sql)),
     *     Promise::fromTask($replicaDb->query($sql)),
     *     Promise::fromTask($cache->get($cacheKey)),
     * ])->await();
     *
     * // Example 3: Fallback chain
     * $result = Promise::race([
     *     Promise::fromTask($fastApi->getData()),
     *     Promise::fromTask($slowApi->getData()),
     * ])->catch(fn($e) => getDefaultData())
     *   ->await();
     *
     * // Example 4: First available resource
     * $connection = Promise::race([
     *     Promise::fromTask($pool1->acquire()),
     *     Promise::fromTask($pool2->acquire()),
     *     Promise::fromTask($pool3->acquire()),
     * ])->await();
     *
     * // Example 5: Multiple API endpoints
     * $response = Promise::race([
     *     Promise::fromTask($httpClient->sendAsync(new Request('GET', 'https://api1.example.com/data'))),
     *     Promise::fromTask($httpClient->sendAsync(new Request('GET', 'https://api2.example.com/data'))),
     *     Promise::fromTask($httpClient->sendAsync(new Request('GET', 'https://api3.example.com/data'))),
     * ])->await();
     *
     * // Use whichever responds first
     * $data = json_decode($response->getBody(), true);
     * ```
     *
     * Use Cases:
     * - Implementing timeouts
     * - Redundant/failover data sources
     * - Performance optimization (fastest source wins)
     * - Resource pool acquisition
     * - Multi-region API calls
     */
    public static function race(array $items): self
    {
        $tasks = [];
        foreach ($items as $k => $v) {
            $tasks[$k] = $v instanceof self ? $v->task() : ($v instanceof Task ? $v : new CompletedTask($v));
        }
        $task = new FiberTask(static function () use ($tasks) {
            return \scheduler()->race($tasks);
        });
        return new self($task);
    }

    /**
     * Helper to unwrap callback return values into concrete results.
     *
     * This internal method handles the automatic unwrapping of Promises and
     * Tasks returned from callbacks. When a callback returns:
     * - Promise → Extracts and awaits its underlying Task
     * - Task → Awaits the Task to get the result
     * - Other value → Returns as-is
     *
     * This enables seamless composition where callbacks can return Promises
     * or Tasks without requiring explicit await() calls.
     *
     * @param mixed $value The value to unwrap (Promise, Task, or plain value)
     * @return mixed The unwrapped/awaited result
     *
     * @internal This method is for internal use only
     */
    private static function unwrap(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->task()->getResult();
        }
        if ($value instanceof Task) {
            return $value->getResult();
        }
        return $value;
    }
}
