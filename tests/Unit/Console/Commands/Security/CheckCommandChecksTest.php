<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Commands\Security;

use Glueful\Console\Commands\Security\CheckCommand;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;

/**
 * security:check printed "validated" for five steps it never ran: health, file permissions,
 * configuration, authentication and network were hard-coded passes. Each now checks something
 * real and fails when it finds a problem.
 */
final class CheckCommandChecksTest extends TestCase
{
    private string $appPath;

    protected function tearDown(): void
    {
        if (isset($this->appPath)) {
            exec('rm -rf ' . escapeshellarg($this->appPath));
        }
    }

    /** @param array<string, string> $extraConfig file => php array body */
    private function command(array $extraConfig = []): CheckCommand
    {
        RouteManifest::reset();
        $this->appPath = sys_get_temp_dir() . '/glueful-seccheck-' . uniqid('', true);
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        mkdir($this->appPath . '/storage', 0755, true);
        $files = array_merge([
            'app' => "['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => false, "
                . "'key' => str_repeat('k', 32)]",
            'database' => "['engine' => 'sqlite', 'sqlite' => ['primary' => '" . $this->appPath . "/s.sqlite'], "
                . "'pooling' => ['enabled' => false]]",
            'cache' => "['enabled' => true, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]",
            'security' => "['csrf' => ['enabled' => false]]",
            'session' => "['jwt_key' => str_repeat('j', 32), 'token_salt' => str_repeat('s', 32), "
                . "'access_token_lifetime' => 900, 'refresh_token_lifetime' => 604800]",
            'cors' => "['allowed_origins' => ['https://example.com'], 'allow_credentials' => true]",
        ], $extraConfig);
        foreach ($files as $name => $body) {
            file_put_contents("{$cfg}/{$name}.php", "<?php\nreturn {$body};\n");
        }
        file_put_contents($this->appPath . '/.env', "APP_ENV=testing\n");
        chmod($this->appPath . '/.env', 0600);
        $app = Framework::create($this->appPath)->boot(allowReboot: true);

        return new CheckCommand($app->getContainer(), $app->getContext());
    }

    /** @return array{passed: bool, message: string} */
    private function step(CheckCommand $command, string $method): array
    {
        $args = match ($method) {
            'processConfigurationSecurity' => [false, false, false],
            'processAuthenticationSecurity', 'processNetworkSecurity' => [false],
            default => [false, false],
        };

        return (new \ReflectionMethod($command, $method))->invoke($command, ...$args);
    }

    public function testASoundInstallPassesEveryStep(): void
    {
        $command = $this->command();

        foreach (['processHealthChecks', 'processPermissionChecks', 'processConfigurationSecurity',
            'processAuthenticationSecurity', 'processNetworkSecurity'] as $method) {
            $result = $this->step($command, $method);
            self::assertTrue($result['passed'], "{$method}: {$result['message']}");
        }
    }

    public function testAWorldReadableEnvFileFailsFilePermissions(): void
    {
        $command = $this->command();
        chmod($this->appPath . '/.env', 0644);

        $result = $this->step($command, 'processPermissionChecks');

        self::assertFalse($result['passed']);
        self::assertStringContainsString('.env', $result['message']);
    }

    public function testMissingSigningSecretsFailConfiguration(): void
    {
        $command = $this->command(['session' => "['jwt_key' => '', 'token_salt' => null]"]);

        $result = $this->step($command, 'processConfigurationSecurity');

        self::assertFalse($result['passed']);
        self::assertStringContainsString('JWT_KEY', $result['message']);
    }

    public function testADayLongAccessTokenFailsAuthentication(): void
    {
        $command = $this->command(['session' => "['jwt_key' => str_repeat('j', 32), "
            . "'token_salt' => str_repeat('s', 32), 'access_token_lifetime' => 172800]"]);

        $result = $this->step($command, 'processAuthenticationSecurity');

        self::assertFalse($result['passed']);
        self::assertStringContainsString('ACCESS_TOKEN_LIFETIME', $result['message']);
    }

    public function testAnyOriginWithCredentialsFailsNetwork(): void
    {
        $command = $this->command(['cors' => "['allowed_origins' => ['*'], 'allow_credentials' => true]"]);

        $result = $this->step($command, 'processNetworkSecurity');

        self::assertFalse($result['passed']);
        self::assertStringContainsString('CORS', $result['message']);
    }

    public function testAnUnreachableDatabaseFailsHealth(): void
    {
        $command = $this->command(['database' => "['engine' => 'sqlite', 'sqlite' => ['primary' => "
            . "'/nonexistent/dir/x.sqlite'], 'pooling' => ['enabled' => false]]"]);

        $result = $this->step($command, 'processHealthChecks');

        self::assertFalse($result['passed']);
        self::assertStringContainsString('database', strtolower($result['message']));
    }
}
