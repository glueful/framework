<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\System;

use Glueful\Console\BaseCommand;
use Glueful\Support\EnvValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'system:env:validate',
    description: 'Validate presence of required environment variables'
)]
final class EnvValidateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption(
            'keys',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'List of required env keys'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var array<int,string>|null $keys */
        $keys = $input->getOption('keys');
        if ($keys === null || $keys === []) {
            // Default recommended keys
            $keys = [
                'APP_ENV', 'APP_DEBUG', 'APP_KEY',
                // DB (use either DATABASE_URL or discrete variables)
                'DATABASE_URL', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE',
                'DB_USERNAME', 'DB_PASSWORD', 'DB_DRIVER',
                // Cache/Queue (optional)
                'REDIS_URL', 'REDIS_HOST', 'REDIS_PORT',
            ];
        }

        $result = EnvValidator::requireKeys($keys);
        $missing = $result['missing'];

        if ($missing !== []) {
            $this->table(['Missing Keys'], array_map(fn($k) => [$k], $missing));
            $this->warning('Some required environment keys are missing');
            return self::FAILURE;
        }

        $this->success('All required environment keys are present');
        return self::SUCCESS;
    }
}
