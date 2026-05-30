<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\EnabledProviders;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnabledProviders::class)]
final class EnabledProvidersTest extends TestCase
{
    public function testNormalizeStripsLeadingBackslash(): void
    {
        $this->assertSame('Foo\\Bar', EnabledProviders::normalize('\\Foo\\Bar'));
    }

    public function testNormalizeLeavesCleanFqcnUntouched(): void
    {
        $this->assertSame('Foo\\Bar\\Baz', EnabledProviders::normalize('Foo\\Bar\\Baz'));
    }

    public function testFromReadsConfigFiltersAndNormalizes(): void
    {
        $base = sys_get_temp_dir() . '/glueful-ep-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents(
            $base . '/config/extensions.php',
            "<?php\nreturn " . var_export(['enabled' => [
                '\\Vendor\\A\\Provider',  // leading backslash trimmed
                123,                      // non-string, filtered out
                'Vendor\\C\\Provider',
            ]], true) . ";\n"
        );
        $ctx = new ApplicationContext($base, 'testing');
        $ctx->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));

        $this->assertSame(
            ['Vendor\\A\\Provider', 'Vendor\\C\\Provider'],
            EnabledProviders::from($ctx)
        );
    }
}
