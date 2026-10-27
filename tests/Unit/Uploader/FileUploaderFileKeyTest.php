<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Uploader;

use Glueful\Uploader\FileUploader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * handleUpload() with every request file (`$request->files->all()`, keyed by field name) and no
 * `key` treated the whole keyed array as one file, so it always failed with a generic 500
 * "Upload failed". With one file present it is now used; with several and no key the upload fails
 * with a 400 naming the field keys to choose from.
 */
final class FileUploaderFileKeyTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($this->tmp, 'hello');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp);
    }

    private function uploader(): FileUploader
    {
        $uploader = (new \ReflectionClass(FileUploader::class))->newInstanceWithoutConstructor();
        $context = new \ReflectionProperty(FileUploader::class, 'context');
        $context->setAccessible(true);
        $context->setValue($uploader, null);

        return $uploader;
    }

    private function file(string $name = 'a.txt'): UploadedFile
    {
        return new UploadedFile($this->tmp, $name, 'text/plain', UPLOAD_ERR_OK, true);
    }

    /**
     * @param mixed $files
     * @return array<string, mixed>
     */
    private function process(mixed $files, ?string $key): array
    {
        $method = new \ReflectionMethod(FileUploader::class, 'processUploadedFile');
        $method->setAccessible(true);

        return $method->invoke($this->uploader(), $files, $key);
    }

    public function testASingleKeyedFileIsUsedWithoutAKey(): void
    {
        $file = $this->process(['document' => $this->file('doc.txt')], null);

        self::assertSame('doc.txt', $file['name']);
        self::assertSame($this->tmp, $file['tmp_name']);
    }

    public function testAKeyStillSelectsTheFile(): void
    {
        $file = $this->process(['a' => $this->file('a.txt'), 'b' => $this->file('b.txt')], 'b');

        self::assertSame('b.txt', $file['name']);
    }

    public function testABareFileArrayIsStillAccepted(): void
    {
        $raw = ['name' => 'raw.txt', 'type' => 'text/plain', 'tmp_name' => $this->tmp, 'error' => 0, 'size' => 5];

        self::assertSame('raw.txt', $this->process($raw, null)['name']);
    }

    public function testSeveralFilesWithoutAKeyFailWithAValidationErrorNamingTheKeys(): void
    {
        $result = $this->uploader()->handleUpload(
            'token',
            ['user_id' => 'u1'],
            ['avatar' => $this->file(), 'cover' => $this->file()]
        );

        self::assertSame(400, $result['code']);
        self::assertStringContainsString('avatar', (string) $result['error']);
        self::assertStringContainsString('cover', (string) $result['error']);
    }
}
