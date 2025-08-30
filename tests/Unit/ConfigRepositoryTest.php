<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit;

use Glueful\Configuration\ConfigRepository;
use PHPUnit\Framework\TestCase;

final class ConfigRepositoryTest extends TestCase
{
    public function testDeepMergeAssocAndLists(): void
    {
        $frameworkDir = sys_get_temp_dir() . '/cfg-fw-' . uniqid();
        $appDir = sys_get_temp_dir() . '/cfg-app-' . uniqid();
        mkdir($frameworkDir, 0755, true);
        mkdir($appDir, 0755, true);

        file_put_contents(
            $frameworkDir . '/app.php',
            "<?php return [\n  'name' => 'FW',\n  'middleware' => ['a','b'],\n" .
            "  'nested' => ['x' => ['y' => 1]]\n];\n"
        );
        file_put_contents(
            $appDir . '/app.php',
            "<?php return [\n  'name' => 'APP',\n  'middleware' => ['b','c'],\n" .
            "  'nested' => ['x' => ['z' => 2]]\n];\n"
        );

        $repo = new ConfigRepository($frameworkDir, $appDir);

        $this->assertSame('APP', $repo->get('app.name'));
        $this->assertSame(['a','b','c'], $repo->get('app.middleware'));
        $this->assertSame(1, $repo->get('app.nested.x.y'));
        $this->assertSame(2, $repo->get('app.nested.x.z'));

        // Cleanup
        @unlink($frameworkDir . '/app.php');
        @unlink($appDir . '/app.php');
        @rmdir($frameworkDir);
        @rmdir($appDir);
    }
}
