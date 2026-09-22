<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Storage;

use Glueful\Console\BaseCommand;
use Glueful\Uploader\BlobPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'blobs:purge',
    description: 'Remove deleted uploads past their grace period: the stored file, then the row'
)]
class BlobPurgeCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption(
            'days',
            null,
            InputOption::VALUE_REQUIRED,
            'Purge blobs deleted more than this many days ago (default: uploads.purge_deleted_after_days)'
        )->addOption('limit', null, InputOption::VALUE_REQUIRED, 'At most this many blobs in one run', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $input->getOption('days');
        $days = is_scalar($days) && (string) $days !== ''
            ? (int) $days
            : BlobPurger::graceDays($this->getContext());
        $limit = $input->getOption('limit');
        $limit = is_scalar($limit) ? (int) $limit : 500;

        $result = BlobPurger::fromContext($this->getContext())->purgeDeletedOlderThan($days, $limit);

        $this->success(sprintf(
            'Purged %d deleted blob(s) older than %d day(s).%s',
            $result['purged'],
            $days,
            $result['failed'] > 0 ? " {$result['failed']} kept: their file could not be removed (see the log)." : '',
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
