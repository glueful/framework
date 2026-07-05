<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Glueful\Extensions\Install\InstallJobStore;
use Glueful\Support\Process\ComposerBinaryResolver;
use Glueful\Support\Process\ProcessRunner;
use Glueful\Support\Process\SymfonyProcessRunner;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The detached install runner: `composer require <package>` then a FRESH-process
 * enable. Launched by DetachedRunner so the install POST returns immediately; this
 * process streams status into InstallJobStore, which the admin page polls.
 *
 * Terminal states:
 *  - failed                — composer/spawn failed (enable never attempted)
 *  - installed_not_enabled — composer installed but auto-enable failed (enableError)
 *  - succeeded             — installed AND enabled (or auto-enable disabled)
 */
#[AsCommand(
    name: 'extensions:install-run',
    description: 'Run a detached extension install (composer require + fresh-process enable)'
)]
final class InstallRunCommand extends BaseCommand
{
    private ComposerBinaryResolver $composer;

    public function __construct(
        ?ContainerInterface $container = null,
        ?ApplicationContext $context = null,
        private ?ProcessRunner $runner = null,
        ?ComposerBinaryResolver $composer = null,
    ) {
        parent::__construct($container, $context);
        $this->runner ??= new SymfonyProcessRunner();
        $this->composer = $composer ?? new ComposerBinaryResolver();
    }

    protected function configure(): void
    {
        $this->addArgument('jobId', InputArgument::REQUIRED);
        $this->addArgument('package', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobId = (string) $input->getArgument('jobId');
        $package = (string) $input->getArgument('package');
        $context = $this->getContext();
        /** @var InstallJobStore $store */
        $store = $this->getService(InstallJobStore::class);
        $cwd = base_path($context);

        $store->markRunning($jobId);

        // SAME resolver as the preflight (honors COMPOSER_BINARY) — never a second search.
        $composer = $this->composer->resolve();
        if ($composer === null) {
            $store->finish($jobId, 'failed', null, 'composer binary not found');
            return self::FAILURE;
        }

        $timeout = (float) config($context, 'extensions.install.timeout', 600);
        $install = $this->runner->run(
            [$composer, 'require', $package, '--no-interaction', '--no-progress'],
            $cwd,
            $timeout,
            static fn(string $chunk) => $store->appendOutput($jobId, $chunk),
        );

        if ($install['exitCode'] !== 0) {
            $store->finish($jobId, 'failed', $install['exitCode'], 'composer require failed');
            return self::FAILURE;
        }

        $enableError = null;
        if ((bool) config($context, 'extensions.install.auto_enable', true)) {
            $enable = $this->runner->run(
                [PHP_BINARY, base_path($context, 'glueful'), 'extensions:enable-installed', $package],
                $cwd,
                120.0,
                static fn(string $chunk) => $store->appendOutput($jobId, $chunk),
            );
            $decoded = $this->lastJsonLine($enable['output']);
            if (($decoded['ok'] ?? false) !== true) {
                $enableError = is_string($decoded['error'] ?? null) ? $decoded['error'] : 'enable failed';
            }
        }

        // Distinct terminal states: `succeeded` = installed AND enabled; an
        // auto-enable failure is `installed_not_enabled` (recoverable), NOT `failed`.
        if ($enableError !== null) {
            $store->finish($jobId, 'installed_not_enabled', 0, null, $enableError);
            return self::SUCCESS;
        }
        $store->finish($jobId, 'succeeded', 0);
        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function lastJsonLine(string $output): array
    {
        $lines = array_reverse(array_filter(array_map('trim', explode("\n", $output))));
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }
}
