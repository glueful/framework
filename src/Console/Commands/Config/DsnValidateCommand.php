<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Config;

use Glueful\Console\BaseCommand;
use Glueful\Config\DsnParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'config:dsn:validate',
    description: 'Validate and inspect database and Redis DSNs'
)]
final class DsnValidateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption(
            'db',
            'd',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Database DSN(s) to validate'
        )->addOption(
            'redis',
            'r',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Redis DSN(s) to validate'
        )->addOption(
            'from-env',
            'e',
            InputOption::VALUE_NONE,
            'Read DSNs from environment (DATABASE_URL, REDIS_URL) when none provided'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var array<int,string>|null $db */
        $db = $input->getOption('db');
        /** @var array<int,string>|null $redis */
        $redis = $input->getOption('redis');
        $fromEnv = (bool) $input->getOption('from-env');

        // Default behavior: read from env when no explicit values provided
        if (($db === null || $db === []) && ($redis === null || $redis === [])) {
            $fromEnv = true;
        }

        if ($fromEnv) {
            $dbEnvValue = getenv('DATABASE_URL');
            $dbEnv = function_exists('env')
                ? (string) env('DATABASE_URL', '')
                : ($dbEnvValue !== false ? $dbEnvValue : '');
            $redisEnvValue = getenv('REDIS_URL');
            $redisEnv = function_exists('env')
                ? (string) env('REDIS_URL', '')
                : ($redisEnvValue !== false ? $redisEnvValue : '');
            if (($db === null || $db === []) && $dbEnv !== '') {
                $db = [$dbEnv];
            }
            if (($redis === null || $redis === []) && $redisEnv !== '') {
                $redis = [$redisEnv];
            }
        }

        $hadError = false;

        if (is_array($db) && $db !== []) {
            $this->info('Validating Database DSN(s)');
            $rows = [];
            foreach ($db as $dsn) {
                try {
                    $parsed = DsnParser::parseDbDsn($dsn);
                    $rows[] = [
                        'OK',
                        $dsn,
                        (string) ($parsed['driver'] ?? ''),
                        (string) ($parsed['host'] ?? ''),
                        (string) ($parsed['port'] ?? ''),
                        (string) ($parsed['dbname'] ?? ''),
                        (string) ($parsed['user'] ?? ''),
                    ];
                } catch (\Throwable $e) {
                    $hadError = true;
                    $rows[] = ['ERR', $dsn, '-', '-', '-', '-', $e->getMessage()];
                }
            }
            $this->table(['Status', 'DSN', 'Driver', 'Host', 'Port', 'DB', 'User/Message'], $rows);
        }

        if (is_array($redis) && $redis !== []) {
            $this->info('Validating Redis DSN(s)');
            $rows = [];
            foreach ($redis as $dsn) {
                try {
                    $parsed = DsnParser::parseRedisDsn($dsn);
                    $rows[] = [
                        'OK',
                        $dsn,
                        (string) ($parsed['scheme'] ?? ''),
                        (string) ($parsed['host'] ?? ''),
                        (string) ($parsed['port'] ?? ''),
                        (string) ($parsed['db'] ?? ''),
                    ];
                } catch (\Throwable $e) {
                    $hadError = true;
                    $rows[] = ['ERR', $dsn, '-', '-', '-', $e->getMessage()];
                }
            }
            $this->table(['Status', 'DSN', 'Scheme', 'Host', 'Port', 'DB/Message'], $rows);
        }

        if (($db === null || $db === []) && ($redis === null || $redis === [])) {
            $this->warning('No DSNs provided or found in environment. Provide --db/--redis or use --from-env.');
            return self::FAILURE;
        }

        if ($hadError) {
            $this->error('One or more DSNs are invalid');
            return self::FAILURE;
        }

        $this->success('All DSNs are valid');
        return self::SUCCESS;
    }
}
