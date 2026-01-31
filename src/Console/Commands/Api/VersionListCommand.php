<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Api;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * List all API versions and their status
 *
 * Displays:
 * - All registered versions
 * - Default version
 * - Deprecated versions with sunset dates
 * - Version resolution strategy
 */
#[AsCommand(
    name: 'api:version:list',
    description: 'List all API versions and their status',
    aliases: ['api:versions']
)]
final class VersionListCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io->title('API Versions');

        // Get configuration
        $config = (array) config($this->getContext(), 'api.versioning', []);
        $supported = (array) ($config['supported'] ?? []);
        $deprecated = (array) ($config['deprecated'] ?? []);
        $default = (string) ($config['default'] ?? '1');

        // If no versions configured, show note
        if (count($supported) === 0) {
            $this->io->note('No versions explicitly configured. All versions are accepted.');

            // Show default version
            $this->io->section('Default Configuration');
            $this->io->listing([
                "Default Version: v{$default}",
                'Strategy: ' . ($config['strategy'] ?? 'url_prefix'),
                'Prefix: ' . ($config['prefix'] ?? '/api'),
                'Strict Mode: ' . (($config['strict'] ?? false) ? 'Yes' : 'No'),
            ]);

            return self::SUCCESS;
        }

        // Build version table
        $rows = [];
        foreach ($supported as $version) {
            $version = (string) $version;
            $isDefault = $version === $default;
            $isDeprecated = isset($deprecated[$version]);

            // Build status
            $status = [];
            if ($isDefault) {
                $status[] = '<info>default</info>';
            }
            if ($isDeprecated) {
                $status[] = '<comment>deprecated</comment>';
            }
            if (count($status) === 0) {
                $status[] = '<fg=green>active</>';
            }

            // Build sunset info
            $sunset = '-';
            if ($isDeprecated && is_array($deprecated[$version])) {
                $sunsetDate = $deprecated[$version]['sunset'] ?? null;
                if ($sunsetDate !== null) {
                    try {
                        $date = new \DateTimeImmutable($sunsetDate);
                        $now = new \DateTimeImmutable();
                        $diff = $now->diff($date);
                        $days = (int) $diff->days;
                        $daysText = $diff->invert === 1 ? 'past' : "{$days} days";
                        $sunset = $date->format('Y-m-d') . " ({$daysText})";
                    } catch (\Exception) {
                        $sunset = $sunsetDate;
                    }
                }
            }

            // Get deprecation message
            $message = '-';
            if ($isDeprecated && is_array($deprecated[$version])) {
                $message = $deprecated[$version]['message'] ?? '-';
            }

            $rows[] = [
                'v' . $version,
                implode(', ', $status),
                $sunset,
                $message,
            ];
        }

        $this->table(
            ['Version', 'Status', 'Sunset Date', 'Message'],
            $rows
        );

        // Show configuration summary
        $this->io->section('Configuration');
        $this->io->listing([
            'Strategy: ' . ($config['strategy'] ?? 'url_prefix'),
            'Prefix: ' . ($config['prefix'] ?? '/api'),
            'Strict Mode: ' . (($config['strict'] ?? false) ? 'Yes' : 'No'),
            "Default Version: v{$default}",
        ]);

        // Show resolvers
        $resolvers = (array) ($config['resolvers'] ?? ['url_prefix', 'header', 'query', 'accept']);
        $this->io->section('Active Resolvers (by priority)');
        $this->io->listing(array_map(fn($r) => (string) $r, $resolvers));

        return self::SUCCESS;
    }
}
