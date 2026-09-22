<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Uploader;

use Glueful\Uploader\ImageMetadataStripper;
use PHPUnit\Framework\TestCase;

/**
 * `uploads.security.strip_exif` was documented, on by default, and read by nothing: a phone photo
 * kept its GPS position and camera details in the stored original, for anyone who could fetch it.
 */
final class ImageMetadataStripperTest extends TestCase
{
    private const SECRET = 'GPS-51.5007N-0.1246W';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testAJpegLosesItsMetadataButKeepsItsOrientation(): void
    {
        $file = $this->file($this->jpegWithMetadata(orientation: 6));

        self::assertTrue(ImageMetadataStripper::strip($file, 'image/jpeg'));

        $bytes = (string) file_get_contents($file);
        self::assertStringNotContainsString(self::SECRET, $bytes);
        self::assertNotFalse(@imagecreatefromstring($bytes), 'still a valid image');
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($file);
            self::assertSame(6, (int) ($exif['Orientation'] ?? 0), 'a rotated photo stays upright');
        }
    }

    public function testAnUprightJpegKeepsNoExifAtAll(): void
    {
        $file = $this->file($this->jpegWithMetadata(orientation: 1));

        ImageMetadataStripper::strip($file, 'image/jpeg');

        $bytes = (string) file_get_contents($file);
        self::assertStringNotContainsString(self::SECRET, $bytes);
        self::assertStringNotContainsString("Exif\0\0", $bytes);
    }

    public function testAPngLosesItsExifAndTextChunks(): void
    {
        $file = $this->file($this->pngWithMetadata());

        self::assertTrue(ImageMetadataStripper::strip($file, 'image/png'));

        $bytes = (string) file_get_contents($file);
        self::assertStringNotContainsString(self::SECRET, $bytes);
        self::assertNotFalse(@imagecreatefromstring($bytes));
    }

    public function testAWebpLosesItsExifAndXmpChunks(): void
    {
        if (!function_exists('imagewebp')) {
            self::markTestSkipped('GD without WebP');
        }
        $file = $this->file($this->webpWithMetadata());

        self::assertTrue(ImageMetadataStripper::strip($file, 'image/webp'));

        $bytes = (string) file_get_contents($file);
        self::assertStringNotContainsString(self::SECRET, $bytes);
        self::assertSame(strlen($bytes) - 8, unpack('V', substr($bytes, 4, 4))[1], 'RIFF size matches');
        self::assertNotFalse(@imagecreatefromstring($bytes));
    }

    public function testOtherTypesAreLeftAlone(): void
    {
        $file = $this->file('%PDF-1.4 ' . self::SECRET);

        self::assertFalse(ImageMetadataStripper::strip($file, 'application/pdf'));
        self::assertStringContainsString(self::SECRET, (string) file_get_contents($file));
    }

    private function file(string $bytes): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'strip-');
        file_put_contents($file, $bytes);
        $this->files[] = $file;
        return $file;
    }

    private function gdImage(): \GdImage
    {
        $image = imagecreatetruecolor(4, 2);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 30));
        return $image;
    }

    private function jpegWithMetadata(int $orientation): string
    {
        ob_start();
        imagejpeg($this->gdImage());
        $jpeg = (string) ob_get_clean();

        // Little-endian TIFF: IFD0 with Orientation and an ImageDescription carrying the secret.
        $text = self::SECRET . "\0";
        $ifdOffset = 8;
        $entries = 2;
        $dataOffset = $ifdOffset + 2 + $entries * 12 + 4;
        $tiff = "II*\0" . pack('V', $ifdOffset) . pack('v', $entries)
            . pack('vvVvv', 0x0112, 3, 1, $orientation, 0)
            . pack('vvVV', 0x010E, 2, strlen($text), $dataOffset)
            . pack('V', 0) . $text;
        $app1 = "Exif\0\0" . $tiff;
        $xmp = "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta>" . self::SECRET . '</x:xmpmeta>';
        $segments = "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1
            . "\xFF\xE1" . pack('n', strlen($xmp) + 2) . $xmp
            . "\xFF\xFE" . pack('n', strlen(self::SECRET) + 2) . self::SECRET;

        return substr($jpeg, 0, 2) . $segments . substr($jpeg, 2);
    }

    private function pngWithMetadata(): string
    {
        ob_start();
        imagepng($this->gdImage());
        $png = (string) ob_get_clean();
        $chunk = static fn (string $type, string $data): string
            => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $extra = $chunk('tEXt', 'Comment' . "\0" . self::SECRET) . $chunk('eXIf', 'MM' . self::SECRET);

        // After the 8-byte signature and the 25-byte IHDR chunk.
        return substr($png, 0, 33) . $extra . substr($png, 33);
    }

    private function webpWithMetadata(): string
    {
        ob_start();
        imagewebp($this->gdImage());
        $webp = (string) ob_get_clean();
        $body = substr($webp, 12);
        $chunk = static function (string $type, string $data): string {
            $out = $type . pack('V', strlen($data)) . $data;
            return strlen($data) % 2 === 1 ? $out . "\0" : $out;
        };
        // VP8X header: flags with EXIF (0x08) and XMP (0x04) set, canvas 4x2 (stored minus one).
        $vp8x = $chunk('VP8X', chr(0x0C) . "\0\0\0" . substr(pack('V', 3), 0, 3) . substr(pack('V', 1), 0, 3));
        $payload = 'WEBP' . $vp8x . $body . $chunk('EXIF', self::SECRET) . $chunk('XMP ', self::SECRET);

        return 'RIFF' . pack('V', strlen($payload)) . $payload;
    }
}
