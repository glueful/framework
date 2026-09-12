<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\PackageManifest;
use Glueful\Extensions\Schema\DescriptorValidationException;
use PHPUnit\Framework\TestCase;

final class PackageManifestPreviousSourcesTest extends TestCase
{
    /** @param array<string, mixed> $installedPhp */
    private function manifestFor(array $installedPhp): PackageManifest
    {
        $base = sys_get_temp_dir() . '/glueful-pm-prev-' . uniqid('', true);
        @mkdir($base . '/vendor/composer', 0777, true);
        file_put_contents(
            $base . '/vendor/composer/installed.php',
            "<?php\nreturn " . var_export($installedPhp, true) . ";\n"
        );
        return new PackageManifest(new ApplicationContext($base));
    }

    /** @param array<string, mixed> $row */
    private function withDescriptor(array $row): PackageManifest
    {
        return $this->manifestFor(['versions' => ['glueful/thing-core' => [
            'type' => 'library',
            'extra' => ['glueful' => ['migrations' => [$row + [
                'id' => 'default', 'path' => 'database/migrations', 'priority' => 'default', 'mode' => 'core',
            ]]]],
        ]]]);
    }

    public function testPreviousSourcesAreParsed(): void
    {
        $descriptor = $this->withDescriptor(['previous_sources' => ['app']])
            ->migrationDescriptors()['glueful/thing-core'][0];

        self::assertSame(['app'], $descriptor->previousSources);
    }

    public function testTheKeyIsOptionalAndDefaultsToNone(): void
    {
        $descriptor = $this->withDescriptor([])->migrationDescriptors()['glueful/thing-core'][0];

        self::assertSame([], $descriptor->previousSources);
    }

    public function testANonListValueIsRejected(): void
    {
        $this->expectException(DescriptorValidationException::class);
        $this->withDescriptor(['previous_sources' => 'app'])->migrationDescriptors();
    }

    public function testALanesOwnNameIsNotAPreviousSource(): void
    {
        $this->expectException(DescriptorValidationException::class);
        $this->withDescriptor(['previous_sources' => ['glueful/thing-core']])->migrationDescriptors();
    }
}
