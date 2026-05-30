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
use Glueful\Extensions\ResolverError;
use Glueful\Support\Version;
use Symfony\Component\Console\Input\InputArgument;
use Psr\Container\ContainerInterface;
use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Extensions Disable Command
 *
 * Removes an extension's provider FQCN from config/extensions.php's `enabled`
 * allow-list, then recompiles the cache. Refuses to disable an extension that
 * another still-enabled extension depends on. Development only.
 */
#[AsCommand(
    name: 'extensions:disable',
    description: 'Disable extension (development only)'
)]
final class DisableCommand extends BaseCommand
{
    use ResolvesExtensionNeedle;

    public function __construct(?ContainerInterface $container = null, ?ApplicationContext $context = null)
    {
        parent::__construct($container, $context);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Disable extension (development only)')
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
        // A disabled-but-still-installed extension is resolvable by needle; if the
        // package is gone, fall back to treating the needle as a literal FQCN.
        $providerClass = $this->resolveNeedle($needle, $candidates) ?? ltrim($needle, '\\');

        $current = EnabledProviders::from($context);
        if (!in_array($providerClass, $current, true)) {
            $output->writeln("<info>{$providerClass} is not enabled.</info>");
            return self::SUCCESS;
        }

        // Dry-resolve the proposed list with the provider removed; refuse if that
        // leaves another still-enabled extension with a missing dependency.
        $proposed = array_values(array_filter($current, static fn($p) => $p !== $providerClass));
        $result = (new ExtensionResolver())->resolve($candidates, $proposed, Version::VERSION);
        $blocking = array_filter(
            $result->errors,
            static fn($e) => $e->kind === ResolverError::MISSING_DEPENDENCY
        );
        if ($blocking !== []) {
            foreach ($blocking as $e) {
                $output->writeln("<error>[{$e->kind}] {$e->message}</error>");
            }
            $output->writeln(
                "<error>Not disabling {$providerClass} — another enabled extension depends on it. "
                . "Disable that first.</error>"
            );
            return self::FAILURE;
        }

        $configPath = config_path($context, 'extensions.php');
        try {
            (new ExtensionStateWriter())->disable(
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
            $output->writeln("<comment>Dry run: would disable {$providerClass} in {$configPath}</comment>");
            return self::SUCCESS;
        }

        $output->writeln("<info>Disabled {$providerClass}.</info>");
        $this->recompileCache($output);
        return self::SUCCESS;
    }

    /**
     * Recompile the extension cache after a config write. The config is already
     * written and valid; a failure here only leaves the compiled cache stale,
     * which is recoverable — surface it as a warning.
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
