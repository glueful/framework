<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

use Glueful\Database\Migrations\MigrationPriority;

/**
 * Built-in descriptors for the framework's own migration leaves, so the root package participates
 * in the same sole inventory as every manifest-declared package. Identities preserve today's
 * receipt sources byte-for-byte: the 'default' id collapses to 'glueful/framework' (the auth
 * leaf's historical source) and every other leaf keeps 'glueful/framework:<leaf>'.
 */
final class FrameworkDescriptors
{
    public const PACKAGE = 'glueful/framework';

    private function __construct()
    {
    }

    /** @return list<MigrationDescriptor> */
    public static function all(string $frameworkRoot): array
    {
        $leaves = [
            'default' => 'migrations/auth',
            'extensions' => 'migrations/extensions',
            'locks' => 'migrations/locks',
            'metrics' => 'migrations/metrics',
            'notifications' => 'migrations/notifications',
            'queue' => 'migrations/queue',
            'scheduler' => 'migrations/scheduler',
            'uploads' => 'migrations/uploads',
        ];
        $out = [];
        foreach ($leaves as $id => $path) {
            // The extensions leaf lands in Task 10; tolerate its absence until then so the
            // inventory stays constructible against any checkout.
            if (!is_dir(rtrim($frameworkRoot, '/') . '/' . $path)) {
                continue;
            }
            $out[] = new MigrationDescriptor(
                id: $id,
                package: self::PACKAGE,
                packageType: 'framework',
                relativePath: $path,
                priority: MigrationPriority::FOUNDATION,
                mode: DescriptorMode::Core,
            );
        }
        return $out;
    }
}
