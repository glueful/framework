# Glueful Framework

High‑performance PHP API framework components for building secure, scalable backends. This repository contains the framework runtime (router, DI, HTTP, caching, security, queues, etc.). For a ready‑to‑use application skeleton, see the API skeleton link below.

## Requirements

- PHP 8.2+
- Composer 2+

## Installation

Install as a library into your application:

```bash
composer require glueful/framework
```

If you want a pre‑scaffolded app, use the API skeleton instead (recommended to get started quickly).

## Quick Start (framework usage)

Bootstrap and handle a request:

```php
<?php
declare(strict_types=1);

use Glueful\Framework;
use Symfony\Component\HttpFoundation\Request;

require __DIR__ . '/vendor/autoload.php';

$framework = Framework::create(__DIR__)
    ->withConfigDir(__DIR__ . '/config')
    ->withEnvironment($_ENV['APP_ENV'] ?? 'development');

$app = $framework->boot();

// Define a quick route (see cookbook for best practices)
$router = $app->getContainer()->get(Glueful\Routing\Router::class);
$router->get('/ping', fn() => new Glueful\Http\Response(['ok' => true]));

$request = Request::createFromGlobals();
$response = $app->handle($request);
$response->send();
$app->terminate($request, $response);
```

## Getting Started

- Getting started guide: https://glueful.com/getting-started
- New to Glueful? Begin with:
  - Routing: https://glueful.com/essentials/routing
- Prefer a pre‑scaffolded app? Use the API skeleton (see below).

## Documentation

- Official docs website: https://glueful.com (mirror: https://glueful.dev)
- Changelog: `CHANGELOG.md`
- Roadmap: `ROADMAP.md`
- Breaking changes process: `BREAKING_CHANGE_PROCESS.md`

## Highlights

- Next‑gen Router (fast static/dynamic matching, groups, named routes, PSR‑15 middleware bridge)
- Clean DI over Symfony Container, service providers, lazy services
- Structured config with environment layering and caching
- Observability: logging, metrics, tracing hooks
- Cache drivers (array/file/redis/memcached) with tagging and warmup utilities
- Security: rate limiting, CSRF, headers, lockdown, permission system hooks
- Queues, scheduling, console commands

## API Skeleton (recommended to start)

For a ready‑to‑run project scaffold, use the API skeleton and follow its README. It wires this framework, public entrypoints, config, and examples:

- Packagist: https://packagist.org/packages/glueful/api-skeleton

Quick start:

```bash
composer create-project glueful/api-skeleton my-app
cd my-app
php glueful serve
```

## Contributing

Contributions are welcome. Please open issues or PRs. For larger proposals, consider starting with a discussion referencing the implementation plans in `docs/implementation_plans/`.

## License

MIT — see `LICENSE`.
