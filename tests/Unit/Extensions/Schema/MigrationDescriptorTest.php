<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions\Schema;

use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\Schema\DescriptorMode;
use Glueful\Extensions\Schema\DescriptorValidationException;
use Glueful\Extensions\Schema\MigrationDescriptor;
use PHPUnit\Framework\TestCase;

final class MigrationDescriptorTest extends TestCase
{
    private function descriptor(
        string $id = 'default',
        string $type = 'glueful-extension',
        string $path = 'migrations',
        DescriptorMode $mode = DescriptorMode::OnEnable,
    ): MigrationDescriptor {
        return new MigrationDescriptor(
            id: $id,
            package: 'acme/widgets',
            packageType: $type,
            relativePath: $path,
            priority: MigrationPriority::DEFAULT,
            mode: $mode,
        );
    }

    public function testSourceIsPackageNameForTheDefaultDescriptor(): void
    {
        self::assertSame('acme/widgets', $this->descriptor()->source());
    }

    public function testSourceIsPackageColonIdForNamedDescriptors(): void
    {
        self::assertSame('acme/widgets:tenant', $this->descriptor(id: 'tenant')->source());
    }

    public function testOnEnableRequiresExtensionType(): void
    {
        $this->expectException(DescriptorValidationException::class);
        $this->descriptor(type: 'library', mode: DescriptorMode::OnEnable);
    }

    public function testCoreModeIsValidForLibrariesAndTheFrameworkItself(): void
    {
        self::assertSame(DescriptorMode::Core, $this->descriptor(type: 'library', mode: DescriptorMode::Core)->mode);
        self::assertSame(DescriptorMode::Core, $this->descriptor(type: 'framework', mode: DescriptorMode::Core)->mode);
    }

    public function testTraversalPathsAreRejectedAtConstruction(): void
    {
        $this->expectException(DescriptorValidationException::class);
        $this->descriptor(path: '../outside');
    }

    public function testAbsoluteDeclaredPathsAreRejectedAtConstruction(): void
    {
        $this->expectException(DescriptorValidationException::class);
        $this->descriptor(path: '/etc/passwd');
    }

    public function testAbsolutePathResolvesInsideThePackageDir(): void
    {
        $dir = sys_get_temp_dir() . '/desc_' . uniqid();
        mkdir($dir . '/migrations', 0777, true);
        try {
            self::assertSame(realpath($dir . '/migrations'), $this->descriptor()->absolutePath($dir));
        } finally {
            rmdir($dir . '/migrations');
            rmdir($dir);
        }
    }

    public function testSymlinkEscapingThePackageDirIsRejected(): void
    {
        $outside = sys_get_temp_dir() . '/desc_out_' . uniqid();
        $dir = sys_get_temp_dir() . '/desc_' . uniqid();
        mkdir($outside, 0777, true);
        mkdir($dir, 0777, true);
        symlink($outside, $dir . '/migrations'); // relativePath 'migrations' resolves OUTSIDE $dir
        try {
            $this->expectException(DescriptorValidationException::class);
            $this->descriptor()->absolutePath($dir);
        } finally {
            unlink($dir . '/migrations');
            rmdir($dir);
            rmdir($outside);
        }
    }

    public function testMissingPathIsRejectedByAbsolutePath(): void
    {
        $dir = sys_get_temp_dir() . '/desc_' . uniqid();
        mkdir($dir, 0777, true);
        try {
            $this->expectException(DescriptorValidationException::class);
            $this->descriptor()->absolutePath($dir); // no migrations/ inside
        } finally {
            rmdir($dir);
        }
    }

    public function testEmptyOrInvalidIdIsRejected(): void
    {
        $this->expectException(DescriptorValidationException::class);
        $this->descriptor(id: 'Bad Id!');
    }

    public function testVerifierClassMustLookLikeAnFqcnWhenGiven(): void
    {
        $this->expectException(DescriptorValidationException::class);
        new MigrationDescriptor(
            id: 'default',
            package: 'acme/widgets',
            packageType: 'glueful-extension',
            relativePath: 'migrations',
            priority: MigrationPriority::DEFAULT,
            mode: DescriptorMode::OnEnable,
            verifierClass: 'not a class name',
        );
    }

    public function testValidVerifierClassIsAccepted(): void
    {
        $d = new MigrationDescriptor(
            id: 'default',
            package: 'acme/widgets',
            packageType: 'glueful-extension',
            relativePath: 'migrations',
            priority: MigrationPriority::DEFAULT,
            mode: DescriptorMode::OnEnable,
            verifierClass: 'Acme\\Widgets\\Schema\\WidgetsStructuralVerifier',
        );
        self::assertSame('Acme\\Widgets\\Schema\\WidgetsStructuralVerifier', $d->verifierClass);
    }
}
