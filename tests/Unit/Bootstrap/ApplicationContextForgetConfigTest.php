<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Bootstrap;

use Glueful\Bootstrap\ApplicationContext;
use PHPUnit\Framework\TestCase;

/**
 * Config files read env() and the context caches what they returned. When `.env` changes at
 * runtime (the Installer just wrote real database credentials over the sample's placeholders),
 * the process must be able to drop that cache for one config name and read the new values —
 * overrideConfig() is boot-only and cannot serve a post-boot installer.
 */
final class ApplicationContextForgetConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ctx_forget_' . uniqid();
        mkdir($this->dir . '/config', 0755, true);
        file_put_contents(
            $this->dir . '/config/database.php',
            "<?php return ['pgsql' => ['user' => env('DB_PGSQL_USERNAME', 'unset')]];"
        );
        unset($_ENV['DB_PGSQL_USERNAME']);
    }

    protected function tearDown(): void
    {
        unset($_ENV['DB_PGSQL_USERNAME']);
        @unlink($this->dir . '/config/database.php');
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    public function testForgetConfigRereadsTheFileWithTheCurrentEnvironment(): void
    {
        $_ENV['DB_PGSQL_USERNAME'] = 'your_database_user';
        $context = ApplicationContext::forTesting($this->dir);
        $context->setConfigLoader(new \Glueful\Bootstrap\ConfigurationLoader($this->dir, 'testing', $this->dir . '/config'));

        self::assertSame('your_database_user', $context->getConfig('database.pgsql.user'));

        $_ENV['DB_PGSQL_USERNAME'] = 'thallo_user';
        self::assertSame('your_database_user', $context->getConfig('database.pgsql.user'), 'cached');

        $context->forgetConfig('database');

        self::assertSame('thallo_user', $context->getConfig('database.pgsql.user'));
        self::assertSame('thallo_user', $context->getConfig('database.pgsql')['user'] ?? null, 'the whole name is refreshed');
    }
}
