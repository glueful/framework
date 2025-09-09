<?php

namespace Glueful\Console\Commands;

use Glueful\Console\BaseCommand;
use Glueful\Support\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'version',
    description: 'Display Glueful Framework version information',
    aliases: ['--version', '-V']
)]
class VersionCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Display Glueful Framework version information')
             ->setHelp('This command displays the current Glueful Framework version, release information, ' .
                      'and system compatibility details.')
             ->addOption(
                 'system',
                 's',
                 InputOption::VALUE_NONE,
                 'Show detailed system information including PHP version and environment'
             )
             ->addOption(
                 'json',
                 'j',
                 InputOption::VALUE_NONE,
                 'Output version information in JSON format'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $showSystem = (bool) $input->getOption('system');
        $jsonOutput = (bool) $input->getOption('json');

        if ($jsonOutput) {
            $this->outputJson($showSystem);
        } else {
            $this->outputFormatted($showSystem);
        }

        return self::SUCCESS;
    }

    private function outputJson(bool $includeSystem): void
    {
        $data = [
            'framework' => [
                'name' => 'Glueful Framework',
                'version' => Version::getVersion(),
                'release_name' => Version::getName(),
                'release_date' => Version::getReleaseDate(),
                'min_php_version' => Version::getMinPhpVersion(),
            ]
        ];

        if ($includeSystem) {
            $data['system'] = [
                'php_version' => PHP_VERSION,
                'php_supported' => Version::isPhpVersionSupported(),
                'os' => php_uname('s'),
                'architecture' => php_uname('m'),
                'sapi' => PHP_SAPI,
            ];
        }

        $this->line(json_encode($data, JSON_PRETTY_PRINT));
    }

    private function outputFormatted(bool $includeSystem): void
    {
        // Framework header
        $this->line('');
        $this->line('<info>Glueful Framework</info> <comment>' . Version::getVersion() . '</comment>');
        $this->line('<fg=cyan>' . Version::getName() . '</> (Released: ' . Version::getReleaseDate() . ')');
        $this->line('');

        // System information
        if ($includeSystem) {
            $this->line('<info>System Information:</info>');
            $this->line('  PHP Version:     ' . PHP_VERSION);
            $this->line('  Min PHP Version: ' . Version::getMinPhpVersion());

            $phpSupported = Version::isPhpVersionSupported();
            $phpStatus = $phpSupported ? '<fg=green>✓ Supported</>' : '<fg=red>✗ Unsupported</>';
            $this->line('  PHP Status:      ' . $phpStatus);

            $this->line('  Operating System: ' . php_uname('s') . ' ' . php_uname('r'));
            $this->line('  Architecture:     ' . php_uname('m'));
            $this->line('  SAPI:            ' . PHP_SAPI);
            $this->line('');
        }

        // Quick compatibility check
        if (!Version::isPhpVersionSupported()) {
            $this->warning(
                'Your PHP version (' . PHP_VERSION . ') is below the minimum required version (' .
                Version::getMinPhpVersion() . ')'
            );
            $this->note('Please upgrade PHP for optimal framework compatibility');
        }
    }
}
