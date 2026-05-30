<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\BaseCommand;
use Glueful\Console\Commands\Extensions\Concerns\ResolvesExtensionNeedle;
use Glueful\Extensions\EnabledProviders;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ExtensionResolver;
use Glueful\Extensions\ExtensionStateWriter;
use Glueful\Extensions\PackageManifest;
use Glueful\Support\Version;
use Symfony\Component\Console\Input\InputArgument;
use Psr\Container\ContainerInterface;
use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Extensions Enable Command
 *
 * Adds an installed extension's provider FQCN to config/extensions.php's `enabled`
 * allow-list, then recompiles the extension cache. Validates the PROPOSED list
 * before writing — refuses to leave the config in a broken state. Development only.
 */
#[AsCommand(
    name: 'extensions:enable',
    description: 'Enable extension (development only)'
)]
final class EnableCommand extends BaseCommand
{
    use ResolvesExtensionNeedle;

    public function __construct(?ContainerInterface $container = null, ?ApplicationContext $context = null)
    {
        parent::__construct($container, $context);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Enable extension (development only)')
            ->addArgument('extension', InputArgument::REQUIRED, 'Extension package name, provider class, or slug')
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
        $context = $this->getContext();

        $candidates = (new PackageManifest($context))->getCandidates();
        $providerClass = $this->resolveNeedle($needle, $candidates);
        if ($providerClass === null) {
            $output->writeln("<error>Extension not found among installed packages: {$needle}</error>");
            return self::FAILURE;
        }

        // Current enabled list (normalized string FQCNs).
        $current = EnabledProviders::from($context);
        if (in_array($providerClass, $current, true)) {
            $output->writeln("<info>{$providerClass} is already enabled.</info>");
            return self::SUCCESS;
        }

        // Dry-resolve the PROPOSED list; refuse to write if it would error.
        $proposed = [...$current, $providerClass];
        $result = (new ExtensionResolver())->resolve($candidates, $proposed, Version::VERSION);
        if ($result->hasErrors()) {
            foreach ($result->errors as $e) {
                $output->writeln("<error>[{$e->kind}] {$e->message}</error>");
            }
            $output->writeln(
                "<error>Not enabling {$providerClass} — fix the above (e.g. enable its dependencies) first.</error>"
            );
            return self::FAILURE;
        }

        // Clean → write, then recompile the cache.
        $configPath = config_path($context, 'extensions.php');
        try {
            (new ExtensionStateWriter())->enable(
                $configPath,
                $providerClass,
                dryRun: (bool) $input->getOption('dry-run'),
                backup: (bool) $input->getOption('backup'),
            );
        } catch (\RuntimeException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return self::FAILURE;
        }

        if ($input->getOption('dry-run') === true) {
            $output->writeln("<comment>Dry run: would enable {$providerClass} in {$configPath}</comment>");
            return self::SUCCESS;
        }

        $output->writeln("<info>Enabled {$providerClass}.</info>");
        $this->recompileCache($output);
        return self::SUCCESS;
    }

    /**
     * Recompile the extension cache after a config write. The config is already
     * written and valid (preflighted clean); a failure here only leaves the
     * compiled cache stale, which is recoverable — surface it as a warning.
     */
    private function recompileCache(OutputInterface $output): void
    {
        try {
            $this->getService(ExtensionManager::class)->writeCacheNow();
        } catch (\Throwable $e) {
            $output->writeln(
                "<comment>Config updated, but recompiling the cache failed: {$e->getMessage()}. "
                . "Re-run 'php glueful extensions:cache' (dev boot resolves live in the meantime).</comment>"
            );
        }
    }
}
