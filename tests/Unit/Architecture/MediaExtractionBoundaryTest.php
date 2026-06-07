<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Media extraction — core-only acceptance gate (Phase C, Task C1).
 *
 * Codifies the hard boundary for the rich-media extraction to glueful/media:
 * on a plain core checkout (no glueful/media installed), core's src/, config/
 * and routes/ reference NONE of the moved concrete classes, the two heavy
 * media deps are absent from composer.json's `require`, and the global image()
 * helper is undefined (it is now extension-provided — function-not-found, not a
 * stub).
 *
 * The four moved classes (now living in glueful/media):
 *   Glueful\Services\ImageProcessor          -> Glueful\Extensions\Media\ImageProcessor
 *   Glueful\Services\ImageProcessorInterface -> Glueful\Extensions\Media\Contracts\ImageProcessorInterface
 *   Glueful\Uploader\ThumbnailGenerator      -> Glueful\Extensions\Media\ThumbnailGenerator
 *   Glueful\Uploader\MediaMetadataExtractor  -> Glueful\Extensions\Media\MediaMetadataExtractor
 *
 * Glueful\Uploader\MediaMetadata stays in core (unchanged) and is NOT a needle.
 *
 * DOCUMENTED BOUNDARY GREP — run from the framework repo root. After the C1
 * deletions this MUST return ZERO hits (the only remaining literal occurrences
 * of these class names are the namespace-map prose in CHANGELOG.md / UPGRADE.md
 * and this test file, none of which live under src|config|routes):
 *
 *   grep -rn 'ImageProcessor\|ThumbnailGenerator\|MediaMetadataExtractor\|ImageProvider' \
 *     src/ config/ routes/ --include='*.php' \
 *     | grep -v 'src/Container/Providers/StorageProvider.php'
 *
 * (The StorageProvider exclusion drops a benign one-line comment that mentions
 * "ImageProvider removal"; ImageSecurityValidator — a different class — stays in
 * core. The in-code scan below applies the same exclusion via the
 * MediaProcessorInterface seam carve-out is not needed since the seam's
 * "getID3" prose does not contain any of the four needles.)
 */
final class MediaExtractionBoundaryTest extends TestCase
{
    /**
     * Gate 1: no core source under src/, config/ or routes/ references any of the
     * four moved concrete class names. Implemented as an in-code recursive scan
     * (mirroring the documented grep) rather than a shell-out, so it runs the same
     * everywhere. Excludes the benign StorageProvider comment that mentions
     * "ImageProvider removal".
     */
    public function testNoMovedMediaClassReferencesRemainInCore(): void
    {
        $root = dirname(__DIR__, 3);
        $needles = [
            'ImageProcessor',
            'ThumbnailGenerator',
            'MediaMetadataExtractor',
            'ImageProvider',
        ];

        $hits = [];
        foreach (['src', 'config', 'routes'] as $dir) {
            $scanDir = $root . '/' . $dir;
            if (!is_dir($scanDir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($scanDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getPathname();

                // Benign carve-out: a one-line comment in StorageProvider mentions
                // "ImageProvider removal" while keeping the unrelated
                // ImageSecurityValidator binding (which lives in core).
                $isStorageProvider = str_contains($path, 'src/Container/Providers/StorageProvider.php');

                foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $lineNo => $line) {
                    if ($isStorageProvider && str_contains($line, 'ImageProvider removal')) {
                        continue;
                    }
                    foreach ($needles as $needle) {
                        if (str_contains($line, $needle)) {
                            $hits[] = $path . ':' . ($lineNo + 1) . ' ' . trim($line);
                        }
                    }
                }
            }
        }

        self::assertSame(
            [],
            $hits,
            "Core (src/, config/, routes/) must reference none of the moved media classes after Task C1"
        );
    }

    /**
     * Gate 2: the two heavy media dependencies are gone from composer.json's
     * `require`. They now live in glueful/media.
     */
    public function testHeavyMediaDependenciesAbsentFromComposerRequire(): void
    {
        $composerPath = dirname(__DIR__, 3) . '/composer.json';
        self::assertFileExists($composerPath);

        /** @var array{require?: array<string,string>} $composer */
        $composer = json_decode((string) file_get_contents($composerPath), true);
        self::assertIsArray($composer);

        $require = $composer['require'] ?? [];
        self::assertIsArray($require);

        self::assertArrayNotHasKey(
            'intervention/image',
            $require,
            'intervention/image must move to glueful/media'
        );
        self::assertArrayNotHasKey(
            'james-heinrich/getid3',
            $require,
            'james-heinrich/getid3 must move to glueful/media'
        );
    }

    /**
     * Gate 3: the global image() helper is undefined after core boot. The helper
     * is now provided by glueful/media; an app without that extension has no
     * image() at all (function-not-found, not a stub).
     *
     * Form used: a direct function_exists('image') === false assertion. The
     * framework's own composer test environment does NOT depend on or autoload
     * glueful/media (it is not in composer.json's require and not symlinked into
     * vendor/), so no extension can re-register image() here. The framework's
     * autoload.files still loads src/helpers.php, but the image() block has been
     * removed from it — hence the function is genuinely absent.
     */
    public function testImageHelperIsUndefinedOnPlainCore(): void
    {
        self::assertFalse(
            \function_exists('image'),
            'image() must be undefined on plain core (the helper moved to glueful/media)'
        );
    }
}
