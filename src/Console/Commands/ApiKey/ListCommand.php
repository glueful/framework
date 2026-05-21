<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\ApiKey;

use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'apikey:list', description: 'List API keys for a user')]
final class ListCommand extends BaseCommand
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
            ->setDescription('List API keys for a user')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'User UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userId = $input->getOption('user');
        if (!is_string($userId) || $userId === '') {
            $output->writeln('<error>--user=<uuid> is required</error>');
            return self::FAILURE;
        }

        $keys = ApiKeyService::forUser($this->context, $userId);
        if ($keys === []) {
            $output->writeln('No API keys found for user ' . $userId);
            return self::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['UUID', 'Name', 'Prefix', 'Scopes', 'Allowed IPs', 'Expires At', 'Revoked']);

        foreach ($keys as $key) {
            $scopes = $key->getScopes();
            $ips = $key->getAllowedIps();
            $table->addRow([
                (string) ($key->uuid ?? ''),
                (string) ($key->name ?? ''),
                (string) ($key->key_prefix ?? ''),
                $scopes !== [] ? implode(', ', $scopes) : '—',
                $ips !== [] ? implode(', ', $ips) : '—',
                (string) ($key->expires_at ?? '—'),
                $key->isRevoked() ? 'yes' : 'no',
            ]);
        }

        $table->render();
        return self::SUCCESS;
    }
}
