<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Bootstrap;

use Glueful\Console\BaseCommand;
use Glueful\Console\Commands\Extensions\CacheCommand;
use Glueful\Framework;
use PHPUnit\Framework\TestCase;

/**
 * The environment a boot runs under must be read the way env() reads everything else: from
 * $_ENV, then the real process environment. Reading $_ENV alone chose the default ("production"
 * for the framework, "development" for a skeleton bootstrap) under PHP's default variables_order
 * whenever APP_ENV was exported by the process — a CI job, a container — because Dotenv's
 * immutable loader skips a key the real environment already holds. A CLI run then booted as
 * the wrong environment: its config overrides were not applied, and an extension cache it wrote
 * listed the wrong providers for whoever booted next.
 */
final class EnvironmentDetectionTest extends TestCase
{
    /** @var array{env: mixed, server: mixed, real: string|false} */
    private array $previous;

    protected function setUp(): void
    {
        $this->previous = [
            'env' => $_ENV['APP_ENV'] ?? null,
            'server' => $_SERVER['APP_ENV'] ?? null,
            'real' => getenv('APP_ENV'),
        ];
        unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
        putenv('APP_ENV=from-real-env');
    }

    protected function tearDown(): void
    {
        if ($this->previous['env'] !== null) {
            $_ENV['APP_ENV'] = $this->previous['env'];
        }
        if ($this->previous['server'] !== null) {
            $_SERVER['APP_ENV'] = $this->previous['server'];
        }
        putenv($this->previous['real'] === false ? 'APP_ENV' : 'APP_ENV=' . $this->previous['real']);
    }

    public function testTheFrameworkDefaultsToTheEnvironmentTheRealProcessEnvironmentNames(): void
    {
        $framework = Framework::create(sys_get_temp_dir());

        $environment = (new \ReflectionProperty(Framework::class, 'environment'))->getValue($framework);

        self::assertSame('from-real-env', $environment);
    }

    public function testAConsoleCommandsDefaultContextUsesTheSameEnvironment(): void
    {
        $command = (new \ReflectionClass(CacheCommand::class))->newInstanceWithoutConstructor();
        $build = new \ReflectionMethod(BaseCommand::class, 'buildDefaultContext');

        $context = $build->invoke($command);

        self::assertSame('from-real-env', $context->getEnvironment());
    }
}
