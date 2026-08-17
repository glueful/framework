<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * ExtensionStateWriter is executor-internal plumbing (schema policy spec B5): the ONLY files in
 * src/ that may reference the class — by ANY token: `new`, static call, `use` import, constructor
 * typehint, property type — are the writer itself and the schema executor. A failure here means a
 * caller bypassed the executor; fix the caller, never this allowlist.
 */
final class ExtensionStateWriterCallersTest extends TestCase
{
    private const ALLOWLIST = [
        'src/Extensions/ExtensionStateWriter.php',
        'src/Extensions/Schema/ExtensionSchemaExecutor.php',
    ];

    public function testOnlyTheExecutorReferencesTheStateWriter(): void
    {
        $root = dirname(__DIR__, 3);
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match('/\bExtensionStateWriter\b/', $contents) !== 1) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (!in_array($relative, self::ALLOWLIST, true)) {
                $offenders[] = $relative;
            }
        }
        self::assertSame(
            [],
            $offenders,
            'These files reference ExtensionStateWriter directly; route enabled-state mutation '
            . 'through ExtensionSchemaExecutor instead: ' . implode(', ', $offenders)
        );
    }
}
