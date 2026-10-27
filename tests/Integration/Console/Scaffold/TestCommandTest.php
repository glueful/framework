<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Console\Scaffold;

use Glueful\Console\Commands\Scaffold\TestCommand;

/**
 * A scaffolded feature test must extend the app's booting base (App\Tests\TestCase, the
 * skeleton's tests/TestCase.php) — PHPUnit's bare TestCase has no application to test against.
 */
final class TestCommandTest extends ScaffoldCommandTestCase
{
    private function writeAppTestCase(): void
    {
        @mkdir($this->base . '/tests', 0777, true);
        file_put_contents($this->base . '/tests/TestCase.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Tests;

use Glueful\Testing\TestCase as FrameworkTestCase;

abstract class TestCase extends FrameworkTestCase
{
}
PHP);
    }

    public function testFeatureTestExtendsTheAppTestCase(): void
    {
        $this->writeAppTestCase();
        $tester = $this->tester(TestCommand::class);

        $status = $tester->execute(['name' => 'OrdersTest', '--feature' => true]);

        self::assertSame(0, $status, $tester->getDisplay());
        $file = $this->base . '/tests/Feature/OrdersTest.php';
        self::assertFileExists($file);
        $source = (string) file_get_contents($file);
        self::assertStringContainsString('use App\\Tests\\TestCase;', $source);
        self::assertStringNotContainsString('use PHPUnit\\Framework\\TestCase;', $source);
        $this->assertLints($file);

        $out = $this->runInChild(
            'require ' . var_export($this->base . '/tests/TestCase.php', true) . ";\n"
            . 'require ' . var_export($file, true) . ";\n"
            . 'echo get_parent_class(App\\Tests\\Feature\\OrdersTest::class), "|",'
            . ' (int) is_subclass_of(App\\Tests\\Feature\\OrdersTest::class, Glueful\\Testing\\TestCase::class);'
        );
        self::assertSame('App\\Tests\\TestCase|1', $out);
    }

    public function testFeatureTestFallsBackToPhpunitWithoutAnAppTestCase(): void
    {
        $tester = $this->tester(TestCommand::class);

        self::assertSame(0, $tester->execute(['name' => 'OrdersTest', '--feature' => true]));
        $source = (string) file_get_contents($this->base . '/tests/Feature/OrdersTest.php');
        self::assertStringContainsString('use PHPUnit\\Framework\\TestCase;', $source);
    }

    public function testUnitTestKeepsPhpunitTestCase(): void
    {
        $this->writeAppTestCase();
        $tester = $this->tester(TestCommand::class);

        self::assertSame(0, $tester->execute(['name' => 'MoneyTest']));
        $file = $this->base . '/tests/Unit/MoneyTest.php';
        self::assertStringContainsString('use PHPUnit\\Framework\\TestCase;', (string) file_get_contents($file));
        $this->assertLints($file);
    }
}
