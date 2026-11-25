<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Storage;

use Glueful\Console\BaseCommand;
use Glueful\Database\Connection;
use Glueful\Uploader\BlobVariantStore;
use Glueful\Uploader\BlobVersion;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function config;

#[AsCommand(
    name: 'blobs:variants:prune',
    description: 'Remove stored resized copies of images that are gone or have changed'
)]
class BlobVariantsPruneCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption(
            'all',
            null,
            InputOption::VALUE_NONE,
            'Remove every stored copy; each is made again when it is next requested'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->getContext();
        $store = BlobVariantStore::fromContext($context);
        $db = Connection::fromContext($context);
        $all = (bool) $input->getOption('all');

        $current = static function (string $uuid) use ($db): ?string {
            $blob = $db->table('blobs')
                ->select(['uuid', 'updated_at', 'size'])
                ->where('uuid', '=', $uuid)
                ->where('status', '=', 'active')
                ->first();

            return is_array($blob) ? BlobVersion::of($blob) : null;
        };

        $images = 0;
        $removed = 0;
        foreach ($this->disks($store, $db, (string) config($context, 'uploads.disk', 'uploads')) as $disk) {
            try {
                $result = $store->prune($disk, $current, $all);
            } catch (\Throwable $e) {
                $this->warning("Skipped disk {$disk}: {$e->getMessage()}");
                continue;
            }
            $images += $result['images'];
            $removed += $result['removed'];
        }

        $this->success(sprintf(
            'Checked stored copies of %d image(s); removed %d folder(s).',
            $images,
            $removed,
        ));

        return self::SUCCESS;
    }

    /**
     * Every disk copies may be on: the variant disk, or the default disk and each disk an image
     * names — including one images have since moved off, as long as some image still names it.
     *
     * @return list<string>
     */
    private function disks(BlobVariantStore $store, Connection $db, string $default): array
    {
        if ($store->variantDisk() !== null) {
            return [$store->variantDisk()];
        }
        $disks = [$default];
        $rows = $db->table('blobs')->withTrashed()->select(['storage_type'])->distinct()->get();
        foreach ($rows as $row) {
            $disk = (string) ($row['storage_type'] ?? '');
            if ($disk !== '' && !in_array($disk, $disks, true)) {
                $disks[] = $disk;
            }
        }

        return $disks;
    }
}
