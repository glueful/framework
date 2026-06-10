<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Storage;

use Glueful\Console\BaseCommand;
use Glueful\Storage\Contracts\StorageDriverRegistryInterface;
use Glueful\Storage\Contracts\StorageHealthCheckInterface;
use Glueful\Storage\Exceptions\UnsupportedStorageDriverException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'storage:test',
    description: 'Diagnose storage disks: registration, adapter availability, liveness (read-only by default)'
)]
final class StorageTestCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Diagnose storage disks (read-only by default)')
            ->addArgument('disk', InputArgument::OPTIONAL, 'Limit to a single disk name')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Run a write/read/delete smoke test');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var StorageDriverRegistryInterface $registry */
        $registry = container($this->getContext())->get(StorageDriverRegistryInterface::class);
        /** @var array<string, mixed> $config */
        $config = (array) config($this->getContext(), 'storage', []);

        $only = $input->getArgument('disk');
        if (is_string($only) && $only !== '') {
            $disks = (array) ($config['disks'] ?? []);
            $config['disks'] = isset($disks[$only]) ? [$only => $disks[$only]] : [];
        }

        $report = self::buildReport($registry, $config, (bool) $input->getOption('write'));
        if ($report === []) {
            $name = is_string($only) && $only !== '' ? $only : 'configured disks';
            $output->writeln("<error>No storage disk found for {$name}.</error>");

            return self::FAILURE;
        }

        $failed = false;
        foreach ($report as $disk => $row) {
            $status = ($row['available'] && ($row['liveness'] !== false) && ($row['ok'] !== false))
                ? '<info>OK</info>'
                : '<comment>CHECK</comment>';
            if (!$row['available'] || $row['ok'] === false) {
                $failed = true;
            }

            $output->writeln(sprintf(
                '%s  %-20s driver=%s registered=%s available=%s wrote=%s  %s',
                $status,
                $disk,
                (string) $row['driver'],
                $row['registered'] ? 'yes' : 'no',
                $row['available'] ? 'yes' : 'no',
                $row['wrote'] ? 'yes' : 'no',
                (string) $row['message']
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $storageConfig
     * @return array<string, array{
     *   driver: string,
     *   registered: bool,
     *   available: bool,
     *   liveness: bool|null,
     *   wrote: bool,
     *   ok: bool|null,
     *   message: string
     * }>
     */
    public static function buildReport(
        StorageDriverRegistryInterface $registry,
        array $storageConfig,
        bool $write
    ): array {
        $report = [];
        /** @var array<string, array<string, mixed>> $disks */
        $disks = (array) ($storageConfig['disks'] ?? []);

        foreach ($disks as $name => $diskConfig) {
            $driver = (string) ($diskConfig['driver'] ?? '');
            $registered = $registry->has($driver);

            $row = [
                'driver' => $driver,
                'registered' => $registered,
                'available' => false,
                'liveness' => null,
                'wrote' => false,
                'ok' => null,
                'message' => '',
            ];

            if (!$registered) {
                $row['message'] = UnsupportedStorageDriverException::forDriver($driver)->getMessage();
                $report[(string) $name] = $row;
                continue;
            }

            $factory = $registry->get($driver);
            $row['available'] = $factory->available($diskConfig);
            if (!$row['available']) {
                $row['message'] = 'Driver registered but adapter/config unavailable.';
                $report[(string) $name] = $row;
                continue;
            }

            if ($factory instanceof StorageHealthCheckInterface) {
                $check = $factory->check((string) $name, $diskConfig);
                $row['liveness'] = (bool) ($check['ok'] ?? false);
                $row['message'] = (string) ($check['message'] ?? '');
            }

            if ($write) {
                $row = array_merge($row, self::smokeTest($factory->create($diskConfig)));
            } else {
                $row['ok'] = $row['liveness'];
                if ($row['message'] === '') {
                    $row['message'] = 'Registered and available (read-only check).';
                }
            }

            $report[(string) $name] = $row;
        }

        return $report;
    }

    /**
     * @return array{wrote: bool, ok: bool, message: string}
     */
    private static function smokeTest(FilesystemOperator $fs): array
    {
        $probe = '.glueful-storage-test-' . bin2hex(random_bytes(6));
        try {
            $fs->write($probe, 'ok');
            $read = $fs->read($probe) === 'ok';
            $fs->delete($probe);

            return [
                'wrote' => true,
                'ok' => $read,
                'message' => $read ? 'Write/read/delete smoke test passed.' : 'Read-back mismatch.',
            ];
        } catch (\Throwable $e) {
            return ['wrote' => true, 'ok' => false, 'message' => 'Smoke test failed: ' . $e->getMessage()];
        }
    }
}
