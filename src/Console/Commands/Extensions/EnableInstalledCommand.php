<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\EnabledProviders;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ExtensionResolver;
use Glueful\Extensions\ExtensionStateWriter;
use Glueful\Extensions\PackageManifest;
use Glueful\Support\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Enable a just-installed extension — internal, runs in a FRESH PHP process.
 *
 * The install runner spawns this in a new process precisely because its own
 * in-memory autoloader predates `composer require` and cannot see the new provider
 * class; a fresh process loads the freshly-generated vendor/autoload.php. Unlike
 * the interactive `extensions:enable`, this has NO APP_ENV=production guard — it is
 * only reachable via the runner, which is gated upstream by the HTTP kill-switch,
 * `system.config.edit`, and host-capability checks. Emits a single JSON line the
 * runner parses.
 */
#[AsCommand(
    name: 'extensions:enable-installed',
    description: 'Enable a just-installed extension (internal; runs in a fresh PHP process)'
)]
final class EnableInstalledCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('package', InputArgument::REQUIRED, 'Composer package, e.g. glueful/aegis');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $package = (string) $input->getArgument('package');
        $context = $this->getContext();

        $candidates = (new PackageManifest($context))->getCandidates();
        $candidate = $candidates[$package] ?? null;
        if ($candidate === null) {
            return $this->emit($output, false, error: "Package not installed or not a Glueful extension: {$package}");
        }
        $provider = $candidate->provider;

        $current = EnabledProviders::from($context);
        if (in_array($provider, $current, true)) {
            return $this->emit($output, true, provider: $provider);
        }

        $proposed = [...$current, $provider];
        $result = (new ExtensionResolver())->resolve($candidates, $proposed, Version::VERSION);
        if ($result->hasErrors()) {
            $message = implode('; ', array_map(
                static fn($e) => "[{$e->kind}] {$e->message}",
                $result->errors,
            ));
            return $this->emit($output, false, error: $message);
        }

        try {
            (new ExtensionStateWriter())->enable(config_path($context, 'extensions.php'), $provider);
            $this->getService(ExtensionManager::class)->writeCacheNow();
        } catch (\Throwable $e) {
            return $this->emit($output, false, error: $e->getMessage());
        }

        return $this->emit($output, true, provider: $provider);
    }

    private function emit(OutputInterface $output, bool $ok, ?string $provider = null, ?string $error = null): int
    {
        $payload = ['ok' => $ok];
        if ($provider !== null) {
            $payload['provider'] = $provider;
        }
        if ($error !== null) {
            $payload['error'] = $error;
        }
        $output->writeln((string) json_encode($payload));
        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
