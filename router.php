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

// Check if the requested file exists as a static file
$staticFile = $documentRoot . $uri;

// Serve static files if they exist (and are not PHP files and are actual files)
if ($uri !== '/' && is_file($staticFile)) {
    $extension = pathinfo($staticFile, PATHINFO_EXTENSION);

    // Don't serve PHP files as static - route them through index.php
    if (strtolower($extension) === 'php') {
        require $documentRoot . '/index.php';
        return;
    }

    // Let PHP built-in server handle actual static files
    return false;
}

// Route everything else through index.php
require $documentRoot . '/index.php';
