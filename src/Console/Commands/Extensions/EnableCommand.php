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
 * Extensions Enable Command
 *
 * Enable extension in development environment by editing config/extensions.php.
 * This is a development-only convenience command.
 */
#[AsCommand(
    name: 'extensions:enable',
    description: 'Enable extension (development only)'
)]
final class EnableCommand extends BaseCommand
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
            ->setDescription('Enable extension (development only)')
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

        if (str_contains($content, $providerClass . '::class')) {
            $output->writeln("<info>{$providerClass} is already enabled.</info>");
            return self::SUCCESS;
        }

        if (preg_match("/'only'\\s*=>\\s*\\[/", $content) === 1) {
            $output->writeln(
                '<comment>Note: "only" mode appears set; enabled list is ignored when only is configured.</comment>'
            );
        }

        $pattern = "/('enabled'\\s*=>\\s*\\[)([^\\]]*?)(\\],)/s";
        $replacement = "$1$2        {$providerClass}::class,\n    $3";
        $updated = preg_replace($pattern, $replacement, $content, 1);

        if ($updated === null || $updated === $content) {
            $output->writeln(
                "<error>Failed to locate the 'enabled' array in {$configPath}. Please edit it manually.</error>"
            );
            return self::FAILURE;
        }

        if ($input->getOption('dry-run') === true) {
            $output->writeln('<comment>Dry run:</comment>');
            $output->writeln("Would add {$providerClass}::class to {$configPath}");
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

        $output->writeln("<info>Enabled {$providerClass} in {$configPath}</info>");
        $output->writeln('<comment>Development-only command</comment>');
        $output->writeln(
            '<info>Note: In production, manage extensions through configuration files and deployment.</info>'
        );

        return self::SUCCESS;
    }
}
