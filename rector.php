<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;

/**
 * Conservative wave-0 configuration.
 *
 * In scope: PHP-version sets derived from composer.json ("php": "^8.3") and
 * PHPUnit upgrade sets matched to the installed PHPUnit major.
 *
 * Deliberately OUT of scope for now:
 * - dead-code / code-quality prepared sets — the framework is reflection-heavy
 *   (DI autowiring, attribute routing, command-manifest discovery), so
 *   dead-code detection false-positives on code only reflection reaches;
 * - type-declaration sets — the level-8 typing campaign applies these
 *   per component, verified by the matching phpstan:* script, not repo-wide;
 * - withImportNames() — repo-wide import churn stays out of mechanical waves.
 *
 * Usage: composer rector (dry-run, advisory) / composer rector:fix (apply).
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        // Deprecated-frozen legacy exception bridge — do not modernize.
        __DIR__ . '/src/Exceptions/ExceptionHandler.php',
        // Global helpers with dynamic signatures (also excluded from PHPStan).
        __DIR__ . '/src/helpers.php',
        // BC hazards for a framework: extensions subclass framework classes,
        // and a non-readonly subclass cannot extend a readonly class; making
        // a property readonly breaks any subclass or consumer that writes it.
        ReadOnlyClassRector::class,
        ReadOnlyPropertyRector::class,
    ])
    ->withPhpSets()
    ->withComposerBased(phpunit: true);
