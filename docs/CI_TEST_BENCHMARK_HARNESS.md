# Minimal Test & Benchmark Harness (CI Seed)

Objective: provide a compact, low-dependency harness to exercise core flows in CI and detect regressions early.

Note: This document contains full code listings for scaffolding. Do not create these files yet; copy them when you are ready to implement tests and the bench harness.

## Tests

- Scope
  - Router: static/dynamic matches, precedence, 404/405, HEAD/OPTIONS.
  - Route cache: save → load → dispatch equivalence (static + dynamic).
  - ExceptionHandler: converts PHP errors, returns JSON envelope, request_id consistency.
  - Framework boot: idempotent boot, service availability, routes loaded.

- Suggested Stack
  - phpunit (lightweight), phpstan, php-cs-fixer.
  - composer scripts: `test`, `stan`, `fix`, `bench`.

- Example phpunit.xml.dist
  ```xml
  <phpunit bootstrap="tests/bootstrap.php" colors="true">
    <testsuites>
      <testsuite name="Core">
        <directory>tests/Core</directory>
      </testsuite>
    </testsuites>
  </phpunit>
  ```

- Example composer.json scripts
  ```json
  {
    "scripts": {
      "test": "phpunit",
      "stan": "phpstan analyse src -l 6",
      "fix": "php-cs-fixer fix --diff",
      "bench": "php tools/bench/bench.php"
    }
  }
  ```

- File: tests/bootstrap.php
  ```php
  <?php
  declare(strict_types=1);
  
  // Composer autoloader (project/vendor or package vendor)
  $autoloadPaths = [
      __DIR__ . '/../vendor/autoload.php',        // project root
      __DIR__ . '/../../vendor/autoload.php',     // package root
  ];
  $loaded = false;
  foreach ($autoloadPaths as $path) {
      if (file_exists($path)) {
          require_once $path;
          $loaded = true;
          break;
      }
  }
  if (!$loaded) {
      fwrite(STDERR, "[tests/bootstrap] Composer autoload not found. Install dependencies to run tests.\n");
  }
  
  // Put ExceptionHandler in test mode when available
  if (class_exists(Glueful\Exceptions\ExceptionHandler::class)) {
      Glueful\Exceptions\ExceptionHandler::setTestMode(true);
  }
  ```

- Example tests (sketch)
  ```php
  // tests/Core/RouterTest.php
  final class RouterTest extends TestCase {
      public function test_static_route(): void {
          $framework = \Glueful\Framework::create(getcwd());
          $app = $framework->boot();
          $router = $app->getContainer()->get(\Glueful\Routing\Router::class);
          $router->get('/ping', fn() => new \Glueful\Http\Response(['ok' => true]));
          $res = $router->dispatch(\Symfony\Component\HttpFoundation\Request::create('/ping','GET'));
          $this->assertSame(200, $res->getStatusCode());
      }
  }
  ```

- File: tests/Core/RouteCacheTest.php
  ```php
  <?php
  declare(strict_types=1);
  
  use PHPUnit\Framework\TestCase;
  use Glueful\Routing\{Router, RouteCache};
  use Glueful\Framework;
  use Symfony\Component\HttpFoundation\Request;
  
  final class RouteCacheTest extends TestCase
  {
      public function test_route_cache_save_and_load(): void
      {
          $app = Framework::create(getcwd())->boot();
          $router = $app->getContainer()->get(Router::class);
          $router->get('/rc', fn() => new \Glueful\Http\Response(['ok' => true]));
          $cache = new RouteCache();
          $this->assertTrue($cache->save($router));
          $loaded = $cache->load();
          $this->assertIsArray($loaded);
          // Dispatch should still succeed after reconstruct
          $res = $router->dispatch(Request::create('/rc','GET'));
          $this->assertSame(200, $res->getStatusCode());
      }
  }
  ```

- File: tests/Core/ExceptionHandlerTest.php
  ```php
  <?php
  declare(strict_types=1);
  
  use PHPUnit\Framework\TestCase;
  use Glueful\Exceptions\ExceptionHandler;
  use Psr\Log\LoggerInterface;
  
  final class ExceptionHandlerTest extends TestCase
  {
      private function fakeLogger(): LoggerInterface
      {
          return new class implements LoggerInterface {
              public function emergency($message, array $context = array()) {}
              public function alert($message, array $context = array()) {}
              public function critical($message, array $context = array()) {}
              public function error($message, array $context = array()) {}
              public function warning($message, array $context = array()) {}
              public function notice($message, array $context = array()) {}
              public function info($message, array $context = array()) {}
              public function debug($message, array $context = array()) {}
              public function log($level, $message, array $context = array()) {}
          };
      }
      
      public function test_json_error_response_captured_in_test_mode(): void
      {
          ExceptionHandler::setTestMode(true);
          ExceptionHandler::setLogger($this->fakeLogger());
          ExceptionHandler::handleException(new \RuntimeException('boom'));
          $resp = ExceptionHandler::getTestResponse();
          $this->assertIsArray($resp);
          $this->assertFalse($resp['success']);
          $this->assertSame(500, $resp['code']);
      }
  }
  ```

- File: tests/Core/FrameworkBootTest.php
  ```php
  <?php
  declare(strict_types=1);
  
  use PHPUnit\Framework\TestCase;
  use Glueful\Framework;
  
  final class FrameworkBootTest extends TestCase
  {
      public function test_framework_boots_and_exposes_container(): void
      {
          $fw = Framework::create(getcwd());
          $app = $fw->boot(allowReboot: true);
          $this->assertTrue($fw->isBooted());
          $this->assertNotNull($app->getContainer());
      }
  }
  ```

## Benchmarks

- Targets
  - Cold boot (Framework::boot) time.
  - Warm dispatch (static route) latency.
  - Warm dispatch (dynamic route with params + 2 middlewares).

- Simple bench script (sketch)
  ```php
  // tools/bench/bench.php
  require __DIR__.'/../../vendor/autoload.php';
  $t = static function(callable $fn, int $n = 1000) { $s=microtime(true); for($i=0;$i<$n;$i++){$fn();} return (microtime(true)-$s)*1000; };
  $fw = \Glueful\Framework::create(getcwd());
  $app = $fw->boot();
  $router = $app->getContainer()->get(\Glueful\Routing\Router::class);
  $router->get('/bench/static', fn() => new \Glueful\Http\Response(['ok'=>true]));
  $req = \Symfony\Component\HttpFoundation\Request::create('/bench/static','GET');
  $ms = $t(fn() => $router->dispatch($req));
  echo "Dispatch x1000: ".$ms."ms\n";
  ```

## CI Pipeline (example)

- Steps
  - composer install --no-interaction --prefer-dist --no-progress
  - vendor/bin/php-cs-fixer --diff --dry-run
  - vendor/bin/phpstan analyse src -l 6
  - vendor/bin/phpunit
  - php tools/bench/bench.php (optional threshold check)

- Thresholds (initial)
  - RouterTest all pass.
  - Benchmarks: report only (no fail) until baselines are established.

## Next Steps

- Add route list/verify command to CI (assert cached vs non-cached equivalence).
- Add instrumentation counters and export a metrics snapshot in CI logs.
- Turn benchmarks into regression gates once baselines are stable.
