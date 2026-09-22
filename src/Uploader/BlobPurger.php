<?php

declare(strict_types=1);

namespace Glueful\Uploader;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Storage\StorageManager;
use Psr\Log\LoggerInterface;

use function config;

/**
 * Removes blobs that were deleted more than a grace period ago: the stored file first, through the
 * disk the blob names, then the row. Deleting a blob only marks it, so without this its bytes stay
 * on the disk for good. A blob whose file cannot be deleted keeps its row, so a later run retries
 * instead of losing track of the file.
 */
final class BlobPurger
{
    public function __construct(
        private readonly Connection $db,
        private readonly StorageManager $storage,
        private readonly string $defaultDisk,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function fromContext(ApplicationContext $context): self
    {
        $container = $context->getContainer();
        $logger = $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null;

        return new self(
            Connection::fromContext($context),
            $container->get(StorageManager::class),
            (string) config($context, 'uploads.disk', 'uploads'),
            $logger instanceof LoggerInterface ? $logger : null,
        );
    }

    /** The configured grace period, in days. */
    public static function graceDays(ApplicationContext $context): int
    {
        return (int) config($context, 'uploads.purge_deleted_after_days', 30);
    }

    /** @return array{purged: int, failed: int} */
    public function purgeDeletedOlderThan(int $days, int $limit = 500): array
    {
        $cutoff = date('Y-m-d H:i:s', time() - max(0, $days) * 86400);
        $rows = $this->db->table('blobs')
            ->withTrashed()
            ->select(['uuid', 'url', 'storage_type'])
            ->where('status', '=', 'deleted')
            // Deleted when deleted_at says; a row marked deleted without it, when last updated.
            ->whereRaw('COALESCE(deleted_at, updated_at) < ?', [$cutoff])
            ->limit(max(1, $limit))
            ->get();

        $purged = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $uuid = (string) $row['uuid'];
            $path = (string) ($row['url'] ?? '');
            $disk = (string) ($row['storage_type'] ?? '');
            try {
                if ($path !== '') {
                    $filesystem = $this->storage->disk($disk !== '' ? $disk : $this->defaultDisk);
                    if ($filesystem->fileExists($path)) {
                        $filesystem->delete($path);
                    }
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->logger?->warning('A deleted blob\'s file could not be removed; the blob is kept.', [
                    'blob_uuid' => $uuid,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
            $this->db->table('blobs')->withTrashed()->where('uuid', '=', $uuid)->forceDelete();
            $purged++;
        }

        return ['purged' => $purged, 'failed' => $failed];
    }
}
