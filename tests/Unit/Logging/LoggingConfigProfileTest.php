<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Logging;

use PHPUnit\Framework\TestCase;

final class LoggingConfigProfileTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    /** @var list<string> */
    private array $managedKeys = [
        'APP_ENV',
        'LOG_PROFILE',
        'LOG_LEVEL',
        'LOG_TO_FILE',
        'LOG_TO_DB',
        'FRAMEWORK_LOG_LEVEL',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->managedKeys as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? false;
            unset($_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->managedKeys as $key) {
            $original = $this->originalEnv[$key] ?? false;
            if ($original === false) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $original;
            }
        }

        parent::tearDown();
    }

    public function testProductionProfileDefaultsAreApplied(): void
    {
        $_ENV['APP_ENV'] = 'production';

        $config = $this->loadLoggingConfig();

        $this->assertSame('production', $config['profile']);
        $this->assertSame('production', $config['default_profile']);
        $this->assertSame('warning', $config['application']['level']);
        $this->assertFalse($config['application']['log_to_db']);
        $this->assertTrue($config['application']['log_to_file']);
        $this->assertSame('warning', $config['framework']['level']);
        $this->assertSame(365, $config['retention']['channels']['auth']);
        $this->assertSame(7, $config['retention']['channels']['debug']);
    }

    public function testLogProfileOverridesAppEnvProfileSelection(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $_ENV['LOG_PROFILE'] = 'staging';

        $config = $this->loadLoggingConfig();

        $this->assertSame('staging', $config['profile']);
        $this->assertSame('development', $config['default_profile']);
        $this->assertSame('info', $config['application']['level']);
        $this->assertSame('info', $config['framework']['level']);
    }

    public function testExplicitEnvValuesOverrideSelectedProfileDefaults(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['LOG_PROFILE'] = 'production';
        $_ENV['LOG_LEVEL'] = 'info';
        $_ENV['LOG_TO_DB'] = 'true';
        $_ENV['LOG_TO_FILE'] = 'false';
        $_ENV['FRAMEWORK_LOG_LEVEL'] = 'error';

        $config = $this->loadLoggingConfig();

        $this->assertSame('info', $config['application']['level']);
        $this->assertTrue($config['application']['log_to_db']);
        $this->assertFalse($config['application']['log_to_file']);
        $this->assertSame('error', $config['framework']['level']);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadLoggingConfig(): array
    {
        $path = dirname(__DIR__, 3) . '/config/logging.php';
        /** @var array<string, mixed> $config */
        $config = require $path;
        return $config;
    }
}
