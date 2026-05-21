<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\ApiKey;

use Glueful\Auth\ApiKey\ApiKey;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'apikey:revoke', description: 'Revoke an API key immediately')]
final class RevokeCommand extends BaseCommand
{
    public function __construct(
        ?ContainerInterface $container = null,
        ?ApplicationContext $context = null
    ) {
        parent::__construct($container, $context);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Revoke an API key — sets revoked_at; the key fails verification immediately')
            ->addArgument('uuid', InputArgument::REQUIRED, 'UUID of the key to revoke');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uuid = $input->getArgument('uuid');
        if (!is_string($uuid) || $uuid === '') {
            $output->writeln('<error>UUID argument is required</error>');
            return self::FAILURE;
        }

        $existing = ApiKey::query($this->context)
            ->where('uuid', '=', $uuid)
            ->first();
        if (!$existing instanceof ApiKey) {
            $output->writeln('<error>No API key found with UUID: ' . $uuid . '</error>');
            return self::FAILURE;
        }

        if ($existing->isRevoked()) {
            $output->writeln('Already revoked: ' . $existing->name . ' (' . $existing->uuid . ')');
            return self::SUCCESS;
        }

        ApiKeyService::revoke($this->context, $existing);

        $output->writeln('Revoked: ' . $existing->name . ' (' . $existing->uuid . ')');
        return self::SUCCESS;
    }
}
