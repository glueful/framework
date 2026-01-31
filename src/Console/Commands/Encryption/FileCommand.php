<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Encryption;

use Glueful\Console\BaseCommand;
use Glueful\Encryption\EncryptionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'encryption:file',
    description: 'Encrypt or decrypt a file'
)]
class FileCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'Action: encrypt or decrypt')
            ->addArgument('source', InputArgument::REQUIRED, 'Source file path')
            ->addArgument('destination', InputArgument::OPTIONAL, 'Destination file path')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite destination if exists')
            ->addOption('delete-source', 'd', InputOption::VALUE_NONE, 'Delete source file after success');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = strtolower((string) $input->getArgument('action'));
        $source = (string) $input->getArgument('source');
        $destination = $input->getArgument('destination');
        $force = (bool) $input->getOption('force');
        $deleteSource = (bool) $input->getOption('delete-source');

        if (!in_array($action, ['encrypt', 'decrypt'], true)) {
            $output->writeln('<error>Action must be "encrypt" or "decrypt".</error>');
            return self::FAILURE;
        }

        if (!is_file($source)) {
            $output->writeln("<error>Source file not found: {$source}</error>");
            return self::FAILURE;
        }

        // Determine destination path
        if ($destination === null) {
            $destination = $action === 'encrypt'
                ? $source . '.enc'
                : (str_ends_with($source, '.enc') ? substr($source, 0, -4) : $source . '.dec');
        }

        if (is_file($destination) && !$force) {
            $output->writeln("<error>Destination file already exists: {$destination}</error>");
            $output->writeln('<comment>Use --force to overwrite.</comment>');
            return self::FAILURE;
        }

        try {
            $service = new EncryptionService($this->getContext());
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to initialize encryption service:</error>');
            $output->writeln('  ' . $e->getMessage());
            return self::FAILURE;
        }

        $sourceSize = filesize($source);
        $output->writeln(sprintf(
            '<info>%s file:</info> %s (%s)',
            ucfirst($action) . 'ing',
            $source,
            $this->formatBytes($sourceSize ?: 0)
        ));

        try {
            if ($action === 'encrypt') {
                $service->encryptFile($source, $destination);
            } else {
                $service->decryptFile($source, $destination);
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>Operation failed:</error>');
            $output->writeln('  ' . $e->getMessage());
            return self::FAILURE;
        }

        $destSize = filesize($destination);
        $output->writeln(sprintf(
            '<info>Output:</info> %s (%s)',
            $destination,
            $this->formatBytes($destSize ?: 0)
        ));

        if ($deleteSource) {
            if (@unlink($source)) {
                $output->writeln('<comment>Source file deleted.</comment>');
            } else {
                $output->writeln('<error>Failed to delete source file.</error>');
            }
        }

        $output->writeln('<info>Done.</info>');
        return self::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2) . ' ' . $units[$i];
    }
}
