<?php

/**
 * PHP Built-in Server Router
 *
 * This router script is used with PHP's built-in development server to properly
 * route all requests through the application. It handles:
 * - Static files (if they exist in the document root)
 * - All other requests through index.php
 *
 * Usage: php -S localhost:8000 -t public router.php
 */

// Get the requested URI path
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

// The document root is set by -t flag (DOCUMENT_ROOT in $_SERVER)
$documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? getcwd();

// Present the front controller the way nginx/Apache do. For a deep link under a mounted SPA
// (public/admin/index.html + /admin/setup) the built-in server resolves the DIRECTORY INDEX:
// SCRIPT_NAME=/admin/index.html, PATH_INFO=/setup. Symfony's Request then infers '/admin' as
// the base path and strips it, so the application router sees '/setup' and the deep link 404s
// locally while real web servers (SCRIPT_NAME=/index.php) serve it. Normalising these
// variables makes the built-in server indistinguishable from a real one for the app.
$presentFrontController = static function (string $documentRoot): void {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $documentRoot . '/index.php';
    unset($_SERVER['PATH_INFO'], $_SERVER['ORIG_PATH_INFO']);
};

// Check if the requested file exists as a static file
$staticFile = $documentRoot . $uri;

// Serve static files if they exist (and are not PHP files and are actual files)
if ($uri !== '/' && is_file($staticFile)) {
    $extension = pathinfo($staticFile, PATHINFO_EXTENSION);

    // Don't serve PHP files as static - route them through index.php
    if (strtolower($extension) === 'php') {
        $presentFrontController($documentRoot);
        require $documentRoot . '/index.php';
        return;
    }

    // Let PHP built-in server handle actual static files
    return false;
}

// Route everything else through index.php
$presentFrontController($documentRoot);
require $documentRoot . '/index.php';
