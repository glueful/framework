<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Commands\Scaffold;

use Glueful\Console\Commands\Scaffold\ModelCommand;
use Glueful\Database\ORM\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The model `scaffold:model` writes must be a class PHP can load: its property declarations
 * have to match the visibility/type the Model base class and its traits declare.
 */
final class ModelCommandTemplateTest extends TestCase
{
    /** @return array<string, array{bool}> */
    public static function variants(): array
    {
        return ['plain' => [false], 'soft deletes' => [true]];
    }

    #[DataProvider('variants')]
    public function testGeneratedModelLintsLoadsAndInstantiates(bool $softDeletes): void
    {
        $className = 'ScaffoldProbe' . bin2hex(random_bytes(4));
        $command = (new \ReflectionClass(ModelCommand::class))->newInstanceWithoutConstructor();
        $generate = new \ReflectionMethod(ModelCommand::class, 'generateModelContent');
        /** @var string $source */
        $source = $generate->invoke($command, $className, $softDeletes, 'name,email', null);

        $file = sys_get_temp_dir() . '/' . $className . '.php';
        file_put_contents($file, $source);

        try {
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $lint, $exit);
            self::assertSame(0, $exit, implode("\n", $lint));

            // A visibility mismatch with the trait is a compile-time fatal, so load the class in a
            // child process first (a fatal here would otherwise kill the whole test run).
            $probe = 'require ' . var_export(dirname(__DIR__, 5) . '/vendor/autoload.php', true) . ';'
                . 'require ' . var_export($file, true) . ';'
                . '$m = new \\App\\Models\\' . $className . '();'
                . 'echo $m->getTable(), "|", var_export($m->usesTimestamps(), true);';
            exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($probe) . ' 2>&1', $out, $exit);
            self::assertSame(0, $exit, implode("\n", $out));

            require $file;
            $fqcn = 'App\\Models\\' . $className;
            $model = new $fqcn();
            self::assertInstanceOf(Model::class, $model);
            self::assertTrue($model->usesTimestamps());
            self::assertSame($softDeletes, method_exists($model, 'trashed'));
        } finally {
            @unlink($file);
        }
    }
}
