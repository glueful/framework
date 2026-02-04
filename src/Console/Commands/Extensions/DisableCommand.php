<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\ExtensionManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Extensions Disable Command
 *
 * Disable extension in development environment by editing config/extensions.php.
 * This is a development-only convenience command.
 */
#[AsCommand(
    name: 'extensions:disable',
    description: 'Disable extension (development only)'
)]
final class DisableCommand extends BaseCommand
{
    private ExtensionManager $extensions;

    public function __construct()
    {
        parent::__construct();
        $this->extensions = $this->getService(ExtensionManager::class);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Disable extension (development only)')
            ->addArgument('extension', InputArgument::REQUIRED, 'Extension provider class or slug')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show changes without writing file')
            ->addOption('backup', null, InputOption::VALUE_NONE, 'Create a .bak backup before writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (env('APP_ENV') === 'production') {
            $output->writeln(
                '<error>This command is not available in production. Edit config/extensions.php directly.</error>'
            );
            return self::FAILURE;
        }

        $needle = (string) $input->getArgument('extension');

        $metaAll = $this->extensions->listMeta();
        $providers = $this->extensions->getProviders();

        $providerClass = null;
        foreach (array_keys($providers) as $class) {
            $meta = $metaAll[$class] ?? [];
            if ($class === $needle || ($meta['slug'] ?? null) === $needle) {
                $providerClass = $class;
                break;
            }
        }

        if ($providerClass === null) {
            $output->writeln("<error>Extension not found: {$needle}</error>");
            return self::FAILURE;
        }

        $context = $this->getContext();
        $configPath = config_path($context, 'extensions.php');

        if (!is_file($configPath) || !is_readable($configPath)) {
            $output->writeln("<error>Cannot read config file: {$configPath}</error>");
            return self::FAILURE;
        }

        $content = file_get_contents($configPath);
        if ($content === false) {
            $output->writeln("<error>Failed to read config file: {$configPath}</error>");
            return self::FAILURE;
        }

        $lines = preg_split("/\\R/", $content);
        if ($lines === false) {
            $output->writeln("<error>Failed to parse config file: {$configPath}</error>");
            return self::FAILURE;
        }

        $changed = false;
        $alreadyDisabled = false;
        foreach ($lines as $i => $line) {
            if (strpos($line, $providerClass . '::class') === false) {
                continue;
            }

            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                $alreadyDisabled = true;
                break;
            }

            $indent = substr($line, 0, strlen($line) - strlen($trimmed));
            $lines[$i] = $indent . '// ' . $trimmed;
            $changed = true;
            break;
        }

        if ($alreadyDisabled) {
            $output->writeln("<info>{$providerClass} is already disabled (commented out).</info>");
            return self::SUCCESS;
        }

        if (!$changed) {
            $output->writeln("<info>{$providerClass} not found in {$configPath}.</info>");
            return self::SUCCESS;
        }

        $updated = implode(PHP_EOL, $lines);

        if ($input->getOption('dry-run') === true) {
            $output->writeln('<comment>Dry run:</comment>');
            $output->writeln("Would comment out {$providerClass}::class in {$configPath}");
            return self::SUCCESS;
        }

        if ($input->getOption('backup') === true) {
            $backupPath = $configPath . '.bak';
            if (!copy($configPath, $backupPath)) {
                $output->writeln("<error>Failed to create backup file: {$backupPath}</error>");
                return self::FAILURE;
            }
        }

        if (file_put_contents($configPath, $updated) === false) {
            $output->writeln("<error>Failed to write config file: {$configPath}</error>");
            return self::FAILURE;
        }

        $output->writeln("<info>Disabled {$providerClass} in {$configPath}</info>");
        $output->writeln('<comment>Development-only command</comment>');
        $output->writeln(
            '<info>Note: In production, manage extensions through configuration files and deployment.</info>'
        );

        return self::SUCCESS;
    }
}
