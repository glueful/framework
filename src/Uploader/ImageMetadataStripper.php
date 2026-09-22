<?php

declare(strict_types=1);

namespace Glueful\Uploader;

/**
 * Removes embedded metadata from an uploaded image, in place and without re-encoding: EXIF (GPS
 * position, camera, timestamps), XMP, IPTC and comments. A JPEG keeps its EXIF orientation, written
 * back as the only tag, so a photo taken sideways is still shown upright. JPEG, PNG and WebP are
 * handled; any other type is left untouched.
 */
final class ImageMetadataStripper
{
    /** @return bool true when the file was rewritten */
    public static function strip(string $path, string $mime): bool
    {
        $bytes = @file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            return false;
        }
        $stripped = match ($mime) {
            'image/jpeg', 'image/jpg', 'image/pjpeg' => self::jpeg($bytes),
            'image/png' => self::png($bytes),
            'image/webp' => self::webp($bytes),
            default => null,
        };
        if ($stripped === null || $stripped === $bytes) {
            return false;
        }
        return file_put_contents($path, $stripped, LOCK_EX) !== false;
    }

    /** Drops APP1 (EXIF, XMP), APP13 (IPTC) and COM segments ahead of the image data. */
    private static function jpeg(string $bytes): ?string
    {
        if (!str_starts_with($bytes, "\xFF\xD8")) {
            return null;
        }
        $out = "\xFF\xD8";
        $orientation = null;
        $pos = 2;
        $length = strlen($bytes);
        while ($pos + 4 <= $length) {
            if ($bytes[$pos] !== "\xFF") {
                return null; // not a marker where one must be: leave the file alone
            }
            $marker = ord($bytes[$pos + 1]);
            if ($marker === 0xDA) {
                break; // start of scan: the rest is image data
            }
            $size = self::int('n', substr($bytes, $pos + 2, 2));
            if ($size < 2 || $pos + 2 + $size > $length) {
                return null;
            }
            $segment = substr($bytes, $pos, 2 + $size);
            $payload = substr($segment, 4);
            if ($marker === 0xE1 && str_starts_with($payload, "Exif\0\0")) {
                $orientation ??= self::exifOrientation(substr($payload, 6));
            } elseif ($marker !== 0xE1 && $marker !== 0xED && $marker !== 0xFE) {
                $out .= $segment;
            }
            $pos += 2 + $size;
        }
        if ($orientation !== null && $orientation !== 1) {
            $app1 = "Exif\0\0" . self::orientationTiff($orientation);
            $out = "\xFF\xD8" . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($out, 2);
        }
        return $out . substr($bytes, $pos);
    }

    /** The Orientation tag (0x0112) from IFD0 of a TIFF structure, or null. */
    private static function exifOrientation(string $tiff): ?int
    {
        $order = substr($tiff, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return null;
        }
        $short = $order === 'II' ? 'v' : 'n';
        $long = $order === 'II' ? 'V' : 'N';
        $ifd = self::int($long, substr($tiff, 4, 4));
        if ($ifd + 2 > strlen($tiff)) {
            return null;
        }
        $count = self::int($short, substr($tiff, $ifd, 2));
        for ($i = 0; $i < $count; $i++) {
            $entry = substr($tiff, $ifd + 2 + $i * 12, 12);
            if (strlen($entry) < 12) {
                return null;
            }
            if (self::int($short, substr($entry, 0, 2)) === 0x0112) {
                $value = self::int($short, substr($entry, 8, 2));
                return $value >= 1 && $value <= 8 ? $value : null;
            }
        }
        return null;
    }

    /** One unpacked integer; 0 when the bytes run short. */
    private static function int(string $format, string $bytes): int
    {
        $value = strlen($bytes) >= ($format === 'n' || $format === 'v' ? 2 : 4) ? unpack($format, $bytes) : false;
        return is_array($value) ? (int) $value[1] : 0;
    }

    private static function orientationTiff(int $orientation): string
    {
        return "II*\0" . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, $orientation, 0) . pack('V', 0);
    }

    /** Drops the eXIf chunk and the text chunks (tEXt, zTXt, iTXt). */
    private static function png(string $bytes): ?string
    {
        $signature = "\x89PNG\r\n\x1A\n";
        if (!str_starts_with($bytes, $signature)) {
            return null;
        }
        $out = $signature;
        $pos = 8;
        $length = strlen($bytes);
        while ($pos + 12 <= $length) {
            $size = self::int('N', substr($bytes, $pos, 4));
            $type = substr($bytes, $pos + 4, 4);
            if ($pos + 12 + $size > $length) {
                return null;
            }
            if (!in_array($type, ['eXIf', 'tEXt', 'zTXt', 'iTXt'], true)) {
                $out .= substr($bytes, $pos, 12 + $size);
            }
            $pos += 12 + $size;
            if ($type === 'IEND') {
                break;
            }
        }
        return $out;
    }

    /** Drops the EXIF and XMP chunks, clears their VP8X flags and fixes the RIFF size. */
    private static function webp(string $bytes): ?string
    {
        if (!str_starts_with($bytes, 'RIFF') || substr($bytes, 8, 4) !== 'WEBP') {
            return null;
        }
        $body = '';
        $pos = 12;
        $length = strlen($bytes);
        while ($pos + 8 <= $length) {
            $type = substr($bytes, $pos, 4);
            $size = self::int('V', substr($bytes, $pos + 4, 4));
            $padded = $size + ($size % 2);
            if ($pos + 8 + $size > $length) {
                return null;
            }
            $chunk = substr($bytes, $pos, 8 + $padded);
            if ($type === 'VP8X' && $size >= 1) {
                $chunk[8] = chr(ord($chunk[8]) & ~0x0C);
            }
            if ($type !== 'EXIF' && $type !== 'XMP ') {
                $body .= $chunk;
            }
            $pos += 8 + $padded;
        }
        $payload = 'WEBP' . $body;
        return 'RIFF' . pack('V', strlen($payload)) . $payload;
    }
}
