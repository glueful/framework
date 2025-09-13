# Setup and Installation

This guide helps you get Glueful running, either via the API Skeleton (recommended) or by installing the framework as a library in an existing app.

## Prerequisites

- PHP 8.2+
- Composer 2+
- Optional: MySQL/PostgreSQL (or SQLite), Redis for cache/queues

## Option A — API Skeleton (recommended)

Creates a ready‑to‑run project wired to the framework with routes, config, and scripts.

```bash
composer create-project glueful/api-skeleton my-api
cd my-api

cp .env.example .env
php glueful generate:key

# For SQLite (default)
mkdir -p storage/database

# For MySQL/Postgres, update .env then run migrations
php glueful migrate:run

# Start dev server
php glueful serve --port=8000
```

Next: start with Routing and Middleware in the Cookbook.

## Option B — Framework as a Library

Install the framework into your app and bootstrap it.

```bash
composer require glueful/framework
```

Minimal bootstrap (example):

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

// Example route
$router = $app->getContainer()->get(Glueful\Routing\Router::class);
$router->get('/ping', fn() => new Glueful\Http\Response(['ok' => true]));

$request = Request::createFromGlobals();
$response = $app->handle($request);
$response->send();
$app->terminate($request, $response);
```

## Environment

Copy `.env.example` and set:

- `APP_ENV`, `APP_DEBUG`, `APP_KEY`, `JWT_KEY`
- DB settings (or use SQLite by default)
- `CACHE_DRIVER` (file/array/redis/memcached)
- For S3 uploads: `STORAGE_DRIVER=s3` + `S3_*` keys

See `.env.example` and topic‑specific pages (e.g., Caching, File Uploads) for details.

## Troubleshooting

- Missing extensions: check `php -m` for required modules
- Permissions: ensure `storage/` is writable
- Config cache: clear with `php glueful cache:clear` (skeleton) or rebuild bootstrap

## Next Steps

- Routing — `docs/cookbook/01-routing.md`
- Middleware — `docs/cookbook/02-middleware.md`
- DI & Services — `docs/cookbook/03-di-and-services.md`
- File Uploads — `docs/cookbook/23-file-uploads.md`
