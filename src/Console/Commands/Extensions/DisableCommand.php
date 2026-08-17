<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\BaseCommand;
use Glueful\Console\Commands\Extensions\Concerns\ReportsExecutorOutcome;
use Glueful\Console\Commands\Extensions\Concerns\ResolvesExtensionNeedle;
use Glueful\Database\Exceptions\LockContentionException;
use Glueful\Extensions\PackageManifest;
use Glueful\Extensions\Schema\ExtensionSchemaExecutor;
use Glueful\Extensions\Schema\SchemaNotBootstrappedException;
use Glueful\Extensions\Schema\UndeclaredSchemaException;
use Symfony\Component\Console\Input\InputArgument;
use Psr\Container\ContainerInterface;
use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Disables an extension through the schema executor (schema policy spec B5). The executor
 * preserves the lifecycle guarantees: protected providers refuse, a provider another enabled
 * extension depends on refuses, no schema is ever changed (tables and data are preserved), and
 * a truthful operation record persists the outcome. Allowed in production.
 */
#[AsCommand(
    name: 'extensions:disable',
    description: 'Disable extension (schema and data are preserved)'
)]
final class DisableCommand extends BaseCommand
{
    use ResolvesExtensionNeedle;
    use ReportsExecutorOutcome;

    public function __construct(?ContainerInterface $container = null, ?ApplicationContext $context = null)
    {
        parent::__construct($container, $context);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Disable extension (schema and data are preserved)')
            ->addArgument('extension', InputArgument::REQUIRED, 'Extension package name, provider class, or slug')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show changes without writing file')
            ->addOption('backup', null, InputOption::VALUE_NONE, 'Create a .bak backup before writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $needle = (string) $input->getArgument('extension');
        $context = $this->getContext();

        $candidates = (new PackageManifest($context))->getCandidates();
        $providerClass = $this->resolveNeedle($needle, $candidates);
        if ($providerClass === null) {
            $output->writeln("<error>Extension not found among installed packages: {$needle}</error>");
            return self::FAILURE;
        }
        $package = null;
        foreach ($candidates as $name => $candidate) {
            if ($candidate->provider === $providerClass) {
                $package = (string) $name;
                break;
            }
        }
        if ($package === null) {
            $output->writeln("<error>No installed package declares provider {$providerClass}.</error>");
            return self::FAILURE;
        }

        try {
            /** @var ExtensionSchemaExecutor $executor */
            $executor = $this->getService(ExtensionSchemaExecutor::class);
            $operation = $executor->disable(
                $package,
                'cli',
                dryRun: (bool) $input->getOption('dry-run'),
                backup: (bool) $input->getOption('backup'),
            );
        } catch (SchemaNotBootstrappedException | UndeclaredSchemaException | LockContentionException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return self::FAILURE;
        } catch (\RuntimeException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return self::FAILURE;
        }

        return $this->reportOperation($output, $operation, 'disable') === 0 ? self::SUCCESS : self::FAILURE;
    }
}
