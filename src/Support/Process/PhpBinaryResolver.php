<?php

declare(strict_types=1);

namespace Glueful\Support\Process;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Resolves a real CLI php to run composer with.
 *
 * Composer ships as a `#!/usr/bin/env php` script, so executing it directly from a
 * web request depends on a usable `php` being on the (often minimal) web PATH — which
 * fails under Apache/php-cgi/nginx+FPM, where the SAPI php is not a CLI interpreter.
 * Invoking `<php-cli> <composer> require …` sidesteps that entirely. Resolution order:
 * explicit `extensions.install.php_binary` override (env `EXTENSIONS_INSTALL_PHP_BINARY`),
 * then Symfony's PhpExecutableFinder, then PHP_BINARY.
 */
final class PhpBinaryResolver
{
    public function __construct(private ApplicationContext $context)
    {
    }

    public function resolve(): string
    {
        $configured = config($this->context, 'extensions.install.php_binary');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        $found = (new PhpExecutableFinder())->find(false); // false → binary only, no server args
        return is_string($found) && $found !== '' ? $found : PHP_BINARY;
    }
}
