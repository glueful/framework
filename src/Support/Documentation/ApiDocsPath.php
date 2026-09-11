<?php

declare(strict_types=1);

namespace Glueful\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;

/**
 * The one place the API reference's URL path is decided.
 *
 * `documentation.route_prefix` (env `API_DOCS_PATH`, default `/api-docs`) names where
 * `routes/docs.php` mounts the reference UI and its `openapi.json`; the generated UI pages,
 * `app.urls.docs` and the generate command's printed URL all derive from it. It was `/docs`,
 * hard-coded in four places, which collided with any application's own documentation.
 */
final class ApiDocsPath
{
    public const DEFAULT = '/api-docs';

    public static function resolve(?ApplicationContext $context): string
    {
        $raw = $context === null ? self::DEFAULT : config($context, 'documentation.route_prefix', self::DEFAULT);

        return self::normalize(is_string($raw) ? $raw : self::DEFAULT);
    }

    /** A leading slash, no trailing slash; empty or the bare root falls back to the default. */
    public static function normalize(string $path): string
    {
        $path = '/' . trim(trim($path), '/');

        return $path === '/' ? self::DEFAULT : $path;
    }
}
