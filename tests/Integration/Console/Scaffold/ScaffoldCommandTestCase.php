<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Console\Scaffold;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Services\FileFinder;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Shared harness for scaffold:* command tests: a throwaway app root, a minimal container, and
 * helpers that lint generated PHP and run it in a child process (so a fatal in generated code
 * fails the test instead of killing the run).
 */
abstract class ScaffoldCommandTestCase extends TestCase
{
    protected string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-scaffold-' . uniqid('', true);
        @mkdir($this->base, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->base);
    }

    /**
     * @param class-string<Command> $commandClass
     */
    protected function tester(string $commandClass, bool $withAppDir = true): CommandTester
    {
        if ($withAppDir) {
            @mkdir($this->base . '/app', 0777, true);
        }
        $ctx = new ApplicationContext($this->base, 'testing');
        $container = new class ($ctx) implements ContainerInterface {
            public function __construct(private ApplicationContext $ctx)
            {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    ApplicationContext::class => $this->ctx,
                    FileFinder::class => new FileFinder(),
                    default => throw new class ("no {$id}") extends \RuntimeException implements
                        \Psr\Container\NotFoundExceptionInterface {
                    },
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [ApplicationContext::class, FileFinder::class], true);
            }
        };

        $tester = new CommandTester(new $commandClass($container, $ctx));
        $tester->setInputs([]);

        return $tester;
    }

    protected function assertLints(string $file): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $lint, $exit);
        self::assertSame(0, $exit, implode("\n", $lint));
    }

    /**
     * Run $code (after the framework autoloader) in a child PHP process; returns its stdout.
     */
    protected function runInChild(string $code): string
    {
        $script = $this->base . '/probe-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents(
            $script,
            "<?php\nrequire " . var_export(dirname(__DIR__, 4) . '/vendor/autoload.php', true) . ";\n" . $code
        );
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $exit);
        self::assertSame(0, $exit, implode("\n", $out));

        return implode("\n", $out);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
