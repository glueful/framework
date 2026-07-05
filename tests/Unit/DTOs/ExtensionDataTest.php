<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\DTOs;

use Glueful\DTOs\ExtensionInstallData;
use Glueful\DTOs\ExtensionToggleData;
use Glueful\Validation\RequestDataHydrator;
use Glueful\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class ExtensionDataTest extends TestCase
{
    private RequestDataHydrator $hydrator;

    protected function setUp(): void
    {
        $this->hydrator = new RequestDataHydrator();
    }

    public function test_install_data_hydrates_package(): void
    {
        $dto = $this->hydrator->hydrate(ExtensionInstallData::class, ['package' => 'glueful/aegis']);
        $this->assertInstanceOf(ExtensionInstallData::class, $dto);
        $this->assertSame('glueful/aegis', $dto->package);
    }

    public function test_install_data_rejects_missing_package(): void
    {
        $this->expectException(ValidationException::class);
        $this->hydrator->hydrate(ExtensionInstallData::class, []);
    }

    public function test_toggle_data_hydrates_package(): void
    {
        $dto = $this->hydrator->hydrate(ExtensionToggleData::class, ['package' => 'glueful/aegis']);
        $this->assertSame('glueful/aegis', $dto->package);
    }

    public function test_toggle_data_rejects_blank_package(): void
    {
        $this->expectException(ValidationException::class);
        $this->hydrator->hydrate(ExtensionToggleData::class, ['package' => '']);
    }
}
