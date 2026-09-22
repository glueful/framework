<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * The mail config defaulted the SMTP host to smtp.mailtrap.io and the sender to
 * noreply@glueful.com, so an app that never configured mail looked configured: the email channel
 * reported itself available and sends failed later, or went out under someone else's domain.
 * Unset now means unset.
 */
final class MailDefaultsTest extends TestCase
{
    public function testUnconfiguredMailHasNoHostAndNoSender(): void
    {
        $saved = [];
        foreach (['MAIL_HOST', 'MAIL_FROM'] as $name) {
            $saved[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        try {
            $config = require dirname(__DIR__, 3) . '/config/services.php';
            self::assertNull($config['mail']['mailers']['smtp']['host']);
            self::assertNull($config['mail']['from']['address']);
        } finally {
            foreach ($saved as $name => [$env, $e, $s]) {
                if ($env !== false) {
                    putenv("{$name}={$env}");
                }
                if ($e !== null) {
                    $_ENV[$name] = $e;
                }
                if ($s !== null) {
                    $_SERVER[$name] = $s;
                }
            }
        }
    }
}
