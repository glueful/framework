<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Services;

use Glueful\Http\Exceptions\Domain\BusinessLogicException;
use Glueful\Services\ImageSecurityValidator;
use PHPUnit\Framework\TestCase;

final class ImageSecurityValidatorTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function testValidateUrlRejectsLinkLocalMetadataIp(): void
    {
        $validator = new ImageSecurityValidator();

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionMessage('Suspicious URL detected');

        $validator->validateUrl('http://169.254.169.254/latest/meta-data');
    }

    public function testValidateUrlTreatsUppercaseHttpSchemeAsExternal(): void
    {
        $validator = new ImageSecurityValidator([
            'disable_external_urls' => true,
        ]);

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionMessage('External image URLs are disabled');

        $validator->validateUrl('HTTP://example.com/image.png');
    }

    public function testValidateImageFileUsesDetectedMimeAndDimensions(): void
    {
        $validator = new ImageSecurityValidator([
            'max_width' => 1,
            'max_height' => 1,
        ]);
        $path = $this->createPngFixture();

        self::assertTrue($validator->validateImageFile($path, 'png'));

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionMessage('Format and MIME type mismatch');

        $validator->validateImageFile($path, 'jpg');
    }

    private function createPngFixture(): string
    {
        $path = sys_get_temp_dir() . '/glueful-validator-' . bin2hex(random_bytes(8)) . '.png';
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
        file_put_contents($path, $png);

        $this->cleanup[] = $path;

        return $path;
    }
}
