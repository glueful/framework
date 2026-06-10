<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Storage;

use Glueful\Storage\Exceptions\UnsupportedStorageDriverException;
use PHPUnit\Framework\TestCase;

final class UnsupportedStorageDriverExceptionTest extends TestCase
{
    public function testKnownProviderDriverIncludesPackageSuggestion(): void
    {
        $e = UnsupportedStorageDriverException::forDriver('s3');

        $this->assertStringContainsString("Unsupported disk driver 's3'", $e->getMessage());
        $this->assertStringContainsString('composer require glueful/storage-s3', $e->getMessage());
    }

    public function testUnknownDriverIncludesRegistrationHint(): void
    {
        $e = UnsupportedStorageDriverException::forDriver('custom');

        $this->assertStringContainsString("Unsupported disk driver 'custom'", $e->getMessage());
        $this->assertStringContainsString('storage.driver_factory', $e->getMessage());
    }
}
