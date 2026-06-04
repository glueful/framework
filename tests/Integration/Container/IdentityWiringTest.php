<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Container;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Auth\{IdentityResolver, NullUserProvider};
use Glueful\Testing\TestCase;

final class IdentityWiringTest extends TestCase
{
    private string $appPath;

    protected function setUp(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-wire-' . uniqid('', true);
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        file_put_contents($cfg . '/app.php', "<?php\nreturn ['name' => 'Test', 'env' => 'testing'];\n");
        file_put_contents(
            $cfg . '/database.php',
            "<?php\nreturn ['engine' => 'sqlite', 'sqlite' => ['primary' => '" . $this->appPath . "/t.sqlite'], "
            . "'pooling' => ['enabled' => false]];\n"
        );
        file_put_contents($cfg . '/cache.php', "<?php\nreturn ['enabled' => false, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]];\n");
        file_put_contents($cfg . '/session.php', "<?php\nreturn ['jwt_key' => 'test'];\n");
        file_put_contents($cfg . '/security.php', "<?php\nreturn ['csrf' => ['enabled' => false]];\n");
        parent::setUp();
    }

    protected function getBasePath(): string
    {
        return $this->appPath;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->appPath) && is_dir($this->appPath)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->appPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->appPath);
        }
    }

    public function test_default_user_provider_is_null_provider(): void
    {
        self::assertInstanceOf(NullUserProvider::class, $this->get(UserProviderInterface::class));
    }

    public function test_identity_resolver_is_resolvable(): void
    {
        self::assertInstanceOf(IdentityResolver::class, $this->get(IdentityResolver::class));
    }
}
