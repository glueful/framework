# Glueful Framework

High‑performance PHP API framework components for building secure, scalable backends. This repository contains the framework runtime (router, DI, HTTP, caching, security, queues, etc.). For a ready‑to‑use application skeleton, see the API skeleton link below.

## Requirements

- PHP 8.3+
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

- **High-Performance Router**: O(1) static route lookup, dynamic route bucketing, attribute-based routing, middleware pipeline
- **Dependency Injection**: Symfony Container with service providers, lazy services, ApplicationContext injection
- **Authentication**: JWT, LDAP, SAML SSO, API keys with session analytics
- **Encryption**: AES-256-GCM authenticated encryption with key rotation support
- **File Uploads**: Blob storage with visibility controls, signed URLs, thumbnail generation, media metadata
- **Caching**: Multi-driver support (Redis/Memcached/File) with tagging and distributed caching
- **Queue System**: Job processing with Redis/Database backends, auto-scaling workers
- **Security**: Enhanced rate limiting, CSRF protection, security headers, lockdown mode
- **Database**: Query builder, migrations, connection pooling (MySQL, PostgreSQL, SQLite)
- **Extensions**: Modular extension system with lifecycle management
- **CLI Tools**: Comprehensive scaffold commands, migrations, cache management

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
