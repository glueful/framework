<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Commands\Generate;

use Glueful\Console\Commands\Generate\ClientCommand;
use PHPUnit\Framework\TestCase;

final class ClientCommandTest extends TestCase
{
    public function testTypescriptBuildsOpenApiTypescriptCommand(): void
    {
        $cmd = new ClientCommand();
        $built = $cmd->buildShellCommand(
            language: 'typescript',
            specPath: '/tmp/openapi.json',
            outputDir: './generated',
        );
        self::assertStringContainsString('openapi-typescript', $built);
        self::assertStringContainsString('/tmp/openapi.json', $built);
        self::assertStringContainsString('./generated/api.d.ts', $built);
    }

    public function testTsAliasMapsToTypescript(): void
    {
        $cmd = new ClientCommand();
        $built = $cmd->buildShellCommand(language: 'ts', specPath: '/x.json', outputDir: './out');
        self::assertStringContainsString('openapi-typescript', $built);
    }

    public function testFallsBackToOpenapiGeneratorCli(): void
    {
        $cmd = new ClientCommand();
        $built = $cmd->buildShellCommand(
            language: 'python',
            specPath: '/tmp/openapi.json',
            outputDir: './generated',
        );
        self::assertStringContainsString('openapi-generator-cli generate', $built);
        self::assertStringContainsString('-g python', $built);
        self::assertStringContainsString("'/tmp/openapi.json'", $built);
    }

    public function testShellArgumentsAreEscaped(): void
    {
        $cmd = new ClientCommand();
        $built = $cmd->buildShellCommand(
            language: 'python',
            specPath: '/path with spaces/openapi.json',
            outputDir: './out',
        );
        // escapeshellarg wraps the path in single quotes
        self::assertStringContainsString("'/path with spaces/openapi.json'", $built);
    }
}
