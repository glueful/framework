<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Api;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Mark an API version as deprecated
 *
 * Generates configuration for deprecating an API version with:
 * - Sunset date (RFC 8594)
 * - Deprecation message
 * - Alternative endpoint
 */
#[AsCommand(
    name: 'api:version:deprecate',
    description: 'Mark an API version as deprecated'
)]
final class VersionDeprecateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument(
                'version',
                InputArgument::REQUIRED,
                'The API version to deprecate (e.g., 1, v1)'
            )
            ->addOption(
                'sunset',
                's',
                InputOption::VALUE_REQUIRED,
                'Sunset date (YYYY-MM-DD)'
            )
            ->addOption(
                'message',
                'm',
                InputOption::VALUE_REQUIRED,
                'Deprecation message'
            )
            ->addOption(
                'alternative',
                'a',
                InputOption::VALUE_REQUIRED,
                'Alternative endpoint URL'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be changed without making changes'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $version = ltrim((string) $input->getArgument('version'), 'vV');
        $sunset = $input->getOption('sunset');
        $message = $input->getOption('message');
        $alternative = $input->getOption('alternative');
        $dryRun = (bool) $input->getOption('dry-run');

        // Validate sunset date if provided
        $sunsetDate = null;
        if ($sunset !== null) {
            try {
                $sunsetDate = new \DateTimeImmutable((string) $sunset);
            } catch (\Exception $e) {
                $this->error("Invalid sunset date format: {$sunset}");
                $this->io->note('Use YYYY-MM-DD format (e.g., 2025-06-01)');
                return self::FAILURE;
            }
        }

        // Get current configuration
        $config = (array) config($this->getContext(), 'api.versioning', []);
        $supported = (array) ($config['supported'] ?? []);

        // Validate version exists in supported versions (if list is not empty)
        if (count($supported) > 0 && !in_array($version, $supported, true)) {
            $this->error("Version '{$version}' is not in the supported versions list.");
            $this->io->note('Supported versions: ' . implode(', ', $supported));
            $this->io->note('Add the version to api.versioning.supported first.');
            return self::FAILURE;
        }

        // Show deprecation details
        $this->io->title('API Version Deprecation');
        $this->io->section('Deprecation Details');

        $details = [
            "Version: v{$version}",
            'Sunset: ' . ($sunsetDate?->format('Y-m-d') ?? 'Not set'),
            'Message: ' . ($message ?? 'Not set'),
            'Alternative: ' . ($alternative ?? 'Not set'),
        ];
        $this->io->listing($details);

        if ($dryRun) {
            $this->io->note('Dry run - no changes made.');
        }

        // Show configuration update
        $this->io->section('Configuration Update');
        $this->showConfigUpdate($version, $sunsetDate, $message, $alternative);

        $this->io->warning([
            'API version deprecation requires manual config update.',
            'Add the configuration above to config/api.php in the versioning.deprecated array.',
        ]);

        $this->success("Version v{$version} deprecation configuration generated.");

        return self::SUCCESS;
    }

    private function showConfigUpdate(
        string $version,
        ?\DateTimeImmutable $sunsetDate,
        ?string $message,
        ?string $alternative
    ): void {
        $configArray = [];

        if ($sunsetDate !== null) {
            $configArray['sunset'] = $sunsetDate->format('Y-m-d');
        }
        if ($message !== null) {
            $configArray['message'] = $message;
        }
        if ($alternative !== null) {
            $configArray['alternative'] = $alternative;
        }

        // Build PHP array representation
        if (count($configArray) > 0) {
            $lines = ["'{$version}' => ["];
            foreach ($configArray as $key => $value) {
                $lines[] = "    '{$key}' => '{$value}',";
            }
            $lines[] = '],';
            $configStr = implode("\n        ", $lines);
        } else {
            $configStr = "'{$version}' => true,";
        }

        $this->io->text([
            'Add to config/api.php:',
            '',
            "'versioning' => [",
            "    'deprecated' => [",
            "        {$configStr}",
            "    ],",
            "],",
        ]);
    }
}
