<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Commands;

use Glueful\Console\Commands\ServeCommand;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ServeCommandSecurityTest extends TestCase
{
    public function testBrowserOpenCommandShellQuotesUrl(): void
    {
        $url = "http://127.0.0.1:8000';touch /tmp/glueful-pwn;'";

        $command = $this->browserOpenCommand('Darwin', $url);

        $this->assertSame('open ' . escapeshellarg($url), $command);
    }

    private function browserOpenCommand(string $family, string $url): ?string
    {
        $command = new ServeCommand();
        $method = new ReflectionMethod($command, 'browserOpenCommand');
        $method->setAccessible(true);
        $result = $method->invoke($command, $family, $url);

        return is_string($result) ? $result : null;
    }
}
