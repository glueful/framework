<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Logging;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Logging\LogManager;
use PHPUnit\Framework\TestCase;

/** A logger created without a context logs into the app's storage, not the framework's own folder. */
final class LogManagerDefaultContextTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/log_default_ctx_' . uniqid();
        mkdir($this->base . '/config', 0755, true);
        file_put_contents($this->base . '/config/app.php', "<?php return ['env' => 'testing'];");
        // An app's own logging config without a fixed directory: the logger resolves it from the app.
        file_put_contents($this->base . '/config/logging.php', "<?php return ['application' => ['log_to_file' => true], 'paths' => ['log_directory' => null]];");
    }

    protected function tearDown(): void
    {
        LogManager::setDefaultContext(null);
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testALoggerWithoutAContextUsesTheBootContextsStorage(): void
    {
        $context = new ApplicationContext(basePath: $this->base, environment: 'testing');
        $context->setConfigLoader(new ConfigurationLoader($this->base, 'testing', $this->base . '/config'));
        LogManager::setDefaultContext($context);
        new LogManager();
        self::assertDirectoryExists($this->base . '/storage/logs');
    }
}
