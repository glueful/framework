<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Console\Scaffold;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\Commands\Scaffold\DtoCommand;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class DtoCommandTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-dto-' . uniqid('', true);
        @mkdir($this->base, 0777, true);
    }

    private function container(ApplicationContext $ctx): ContainerInterface
    {
        return new class ($ctx) implements ContainerInterface {
            public function __construct(private ApplicationContext $ctx)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === ApplicationContext::class) {
                    return $this->ctx;
                }
                throw new class ("no {$id}") extends \RuntimeException implements
                    \Psr\Container\NotFoundExceptionInterface {
                };
            }

            public function has(string $id): bool
            {
                return $id === ApplicationContext::class;
            }
        };
    }

    private function tester(ApplicationContext $ctx): CommandTester
    {
        return new CommandTester(new DtoCommand($this->container($ctx), $ctx));
    }

    public function testScaffoldsRequestDataDtoByDefault(): void
    {
        $ctx = new ApplicationContext($this->base, 'testing');
        $tester = $this->tester($ctx);

        $status = $tester->execute(['name' => 'CreatePostData']);

        $this->assertSame(0, $status, $tester->getDisplay());

        $file = $this->base . '/src/DTOs/CreatePostData.php';
        $this->assertFileExists($file);

        $content = (string) file_get_contents($file);
        $this->assertStringStartsWith("<?php\n", $content);
        $this->assertStringContainsString('namespace Glueful\\DTOs;', $content);
        $this->assertStringContainsString('implements RequestData', $content);
        $this->assertStringContainsString('#[Rule(', $content);
        $this->assertStringContainsString('final class CreatePostData', $content);

        $lint = shell_exec('php -l ' . escapeshellarg($file) . ' 2>&1');
        $this->assertStringContainsString('No syntax errors', (string) $lint);
    }

    public function testScaffoldsResponseDataDtoWithResponseOption(): void
    {
        $ctx = new ApplicationContext($this->base, 'testing');
        $tester = $this->tester($ctx);

        $status = $tester->execute(['name' => 'PostData', '--response' => true]);

        $this->assertSame(0, $status, $tester->getDisplay());

        $file = $this->base . '/src/DTOs/PostData.php';
        $this->assertFileExists($file);

        $content = (string) file_get_contents($file);
        $this->assertStringStartsWith("<?php\n", $content);
        $this->assertStringContainsString('implements ResponseData', $content);
        $this->assertStringNotContainsString('#[Rule(', $content);

        $lint = shell_exec('php -l ' . escapeshellarg($file) . ' 2>&1');
        $this->assertStringContainsString('No syntax errors', (string) $lint);
    }

    public function testScaffoldsIntoAppNamespaceWhenAppDirExists(): void
    {
        @mkdir($this->base . '/app', 0777, true);
        $ctx = new ApplicationContext($this->base, 'testing');
        $tester = $this->tester($ctx);

        $status = $tester->execute(['name' => 'CreatePostData']);

        $this->assertSame(0, $status, $tester->getDisplay());

        $file = $this->base . '/app/DTOs/CreatePostData.php';
        $this->assertFileExists($file);

        $content = (string) file_get_contents($file);
        $this->assertStringContainsString('namespace App\\DTOs;', $content);
    }

    public function testDeclinedOverwriteReturnsFailure(): void
    {
        $ctx = new ApplicationContext($this->base, 'testing');

        $first = $this->tester($ctx);
        $this->assertSame(0, $first->execute(['name' => 'CreatePostData']));

        $second = $this->tester($ctx);
        $second->setInputs(['no']);
        $status = $second->execute(['name' => 'CreatePostData']);

        $this->assertSame(1, $status);
    }

    public function testForceOverwritesExistingFile(): void
    {
        $ctx = new ApplicationContext($this->base, 'testing');

        // First create a request DTO.
        $first = $this->tester($ctx);
        $this->assertSame(0, $first->execute(['name' => 'CreatePostData']));
        $file = $this->base . '/src/DTOs/CreatePostData.php';
        $this->assertStringContainsString('implements RequestData', (string) file_get_contents($file));

        // Re-run with --force (and --response) — overwrites without prompting.
        $second = $this->tester($ctx);
        $status = $second->execute(['name' => 'CreatePostData', '--force' => true, '--response' => true]);

        $this->assertSame(0, $status, $second->getDisplay());
        $this->assertStringContainsString('implements ResponseData', (string) file_get_contents($file));
    }

    public function testRejectsInvalidClassName(): void
    {
        $ctx = new ApplicationContext($this->base, 'testing');
        $tester = $this->tester($ctx);

        $status = $tester->execute(['name' => '123-not-valid']);

        $this->assertSame(1, $status);
    }
}
