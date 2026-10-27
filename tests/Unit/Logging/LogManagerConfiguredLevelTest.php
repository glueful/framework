<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Logging;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Logging\LogManager;
use PHPUnit\Framework\TestCase;

/** LOG_LEVEL (logging.application.level) sets the logger's minimum level. */
final class LogManagerConfiguredLevelTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/log_level_cfg_' . uniqid();
        mkdir($this->base . '/config', 0755, true);
        file_put_contents($this->base . '/config/app.php', "<?php return ['env' => 'testing'];");
    }

    protected function tearDown(): void
    {
        LogManager::setDefaultContext(null);
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    private function loggerWithApplicationConfig(string $application): LogManager
    {
        file_put_contents(
            $this->base . '/config/logging.php',
            "<?php return ['application' => ['log_to_file' => false, 'log_to_db' => false{$application}]];"
        );
        $context = new ApplicationContext(basePath: $this->base, environment: 'testing');
        $context->setConfigLoader(new ConfigurationLoader($this->base, 'testing', $this->base . '/config'));

        return new LogManager('', 30, 'app', $context);
    }

    /** @return list<string> */
    private function loggedMessages(LogManager $logger): array
    {
        return array_values(array_map(static fn(array $e): string => $e['message'], $logger->getRecentLogs()));
    }

    public function testConfiguredLevelFiltersLowerLevels(): void
    {
        $logger = $this->loggerWithApplicationConfig(", 'level' => 'warning'");
        $logger->debug('debug-msg');
        $logger->info('info-msg');
        $logger->warning('warning-msg');
        $logger->error('error-msg');

        self::assertSame(['warning-msg', 'error-msg'], $this->loggedMessages($logger));
    }

    public function testDebugLevelLogsEverything(): void
    {
        $logger = $this->loggerWithApplicationConfig(", 'level' => 'debug'");
        $logger->debug('debug-msg');

        self::assertSame(['debug-msg'], $this->loggedMessages($logger));
    }

    public function testAnUnknownConfiguredLevelFallsBackToTheDefault(): void
    {
        $logger = $this->loggerWithApplicationConfig(", 'level' => 'loud'");
        $logger->debug('debug-msg');

        self::assertSame(['debug-msg'], $this->loggedMessages($logger));
    }
}
