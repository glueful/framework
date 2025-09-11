<?php

// PHPUnit bootstrap for Glueful framework

declare(strict_types=1);

// Ensure Composer autoloader is available
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
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
    fwrite(STDERR, "Composer autoload not found. Run 'composer install'.\n");
    exit(1);
}

// Put ExceptionHandler in test mode when available
if (class_exists(Glueful\Exceptions\ExceptionHandler::class)) {
      Glueful\Exceptions\ExceptionHandler::setTestMode(true);
}

// Set default timezone for consistent test results
date_default_timezone_set('UTC');

// Reduce noise during tests
ini_set('display_errors', '1');
ini_set('error_reporting', (string) (E_ALL));
