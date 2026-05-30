<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionCandidate;
use Glueful\Extensions\PackageManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PackageManifest::class)]
final class PackageManifestCandidatesTest extends TestCase
{
    /** @param array<string, mixed> $installedPhp */
    private function manifestFor(array $installedPhp): PackageManifest
    {
        // Build a temp app dir with vendor/composer/installed.php
        $base = sys_get_temp_dir() . '/glueful-pm-' . uniqid('', true);
        @mkdir($base . '/vendor/composer', 0777, true);
        file_put_contents(
            $base . '/vendor/composer/installed.php',
            "<?php\nreturn " . var_export($installedPhp, true) . ";\n"
        );
        $ctx = new ApplicationContext($base);
        return new PackageManifest($ctx);
    }

    public function testCandidateCapturesProviderAndRequires(): void
    {
        $m = $this->manifestFor([
            'versions' => [
                'glueful/aegis' => [
                    'type' => 'glueful-extension',
                    'extra' => ['glueful' => [
                        'provider' => 'Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider',
                        'requires' => ['glueful' => '>=1.30.0', 'extensions' => []],
                    ]],
                ],
            ],
        ]);

        $candidates = $m->getCandidates();

        $this->assertArrayHasKey('glueful/aegis', $candidates);
        $c = $candidates['glueful/aegis'];
        $this->assertInstanceOf(ExtensionCandidate::class, $c);
        $this->assertSame('Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider', $c->provider);
        $this->assertSame('>=1.30.0', $c->requiresGlueful);
        $this->assertSame([], $c->requiresExtensions);
    }

    public function testCandidateCapturesVersion(): void
    {
        // Composer's installed.json exposes the pretty version as `version`.
        $m = $this->manifestFor([
            'versions' => [
                'glueful/aegis' => [
                    'type' => 'glueful-extension',
                    'version' => 'v1.5.0',
                    'extra' => ['glueful' => [
                        'provider' => 'Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider',
                    ]],
                ],
                'glueful/notiva' => [
                    'type' => 'glueful-extension',
                    // No package version here — falls back to extra.glueful.version.
                    'extra' => ['glueful' => [
                        'provider' => 'Glueful\\Extensions\\Notiva\\NotivaServiceProvider',
                        'version' => '0.8.4',
                    ]],
                ],
            ],
        ]);

        $candidates = $m->getCandidates();
        $this->assertSame('v1.5.0', $candidates['glueful/aegis']->version);
        $this->assertSame('0.8.4', $candidates['glueful/notiva']->version);
    }

    public function testReadsExtraFromInstalledJsonWhenInstalledPhpOmitsIt(): void
    {
        // Mirrors a real Composer install: installed.php carries `type` but NOT
        // `extra`; the full metadata (extra.glueful.provider/requires) lives only
        // in installed.json. getCandidates() must read the json source.
        $base = sys_get_temp_dir() . '/glueful-pm-json-' . uniqid('', true);
        @mkdir($base . '/vendor/composer', 0777, true);
        file_put_contents(
            $base . '/vendor/composer/installed.php',
            "<?php\nreturn " . var_export([
                'versions' => [
                    'glueful/aegis' => [
                        'pretty_version' => 'v1.5.0',
                        'type' => 'glueful-extension',
                        // NOTE: no 'extra' here, exactly like Composer's optimized dump
                    ],
                ],
            ], true) . ";\n"
        );
        file_put_contents(
            $base . '/vendor/composer/installed.json',
            (string) json_encode(['packages' => [[
                'name' => 'glueful/aegis',
                'type' => 'glueful-extension',
                'extra' => ['glueful' => [
                    'provider' => 'Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider',
                    'requires' => ['glueful' => '>=1.30.0', 'extensions' => []],
                ]],
            ]]])
        );

        $candidates = (new PackageManifest(new ApplicationContext($base)))->getCandidates();

        $this->assertArrayHasKey('glueful/aegis', $candidates);
        $this->assertSame(
            'Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider',
            $candidates['glueful/aegis']->provider
        );
        $this->assertSame('>=1.30.0', $candidates['glueful/aegis']->requiresGlueful);
    }

    public function testNonExtensionPackagesAreNotCandidates(): void
    {
        $m = $this->manifestFor([
            'versions' => [
                'vendor/plain' => ['type' => 'library'],
            ],
        ]);

        $this->assertSame([], $m->getCandidates());
    }
}
