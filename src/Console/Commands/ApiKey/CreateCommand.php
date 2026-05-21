<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\ApiKey;

use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'apikey:create', description: 'Create a new API key for a user')]
final class CreateCommand extends BaseCommand
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
            ->setDescription('Create a new API key for a user')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'User UUID')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Developer-facing label')
            ->addOption('scopes', null, InputOption::VALUE_REQUIRED, 'Comma-separated scopes (e.g. read:*,write:posts)')
            ->addOption('ips', null, InputOption::VALUE_REQUIRED, 'Comma-separated CIDR/IPs (e.g. 192.168.1.0/24)')
            ->addOption('expires', null, InputOption::VALUE_REQUIRED, 'Expiration (datetime or relative, e.g. +1year)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userId = $input->getOption('user');
        $name = $input->getOption('name');
        if (!is_string($userId) || $userId === '' || !is_string($name) || $name === '') {
            $output->writeln('<error>--user=<uuid> and --name=<label> are required</error>');
            return self::FAILURE;
        }

        $scopes = self::parseCsv($input->getOption('scopes'));
        $ips = self::parseCsv($input->getOption('ips'));
        $expires = self::parseExpires($input->getOption('expires'));

        $result = ApiKeyService::create($this->context, [
            'user_id'     => $userId,
            'name'        => $name,
            'scopes'      => $scopes !== [] ? $scopes : null,
            'allowed_ips' => $ips !== [] ? $ips : null,
            'expires_at'  => $expires,
        ]);

        $output->writeln('');
        $output->writeln('┌──────────────────────────────────────────────────────────────┐');
        $output->writeln('│  API key created — SAVE THIS NOW. It will not be shown again. │');
        $output->writeln('├──────────────────────────────────────────────────────────────┤');
        $output->writeln('│  ' . $result['plain']);
        $output->writeln('└──────────────────────────────────────────────────────────────┘');
        $output->writeln('UUID: ' . $result['key']->uuid);
        $output->writeln('Name: ' . $result['key']->name);
        if ($scopes !== []) {
            $output->writeln('Scopes: ' . implode(', ', $scopes));
        }
        if ($ips !== []) {
            $output->writeln('Allowed IPs: ' . implode(', ', $ips));
        }
        if ($expires !== null) {
            $output->writeln('Expires at: ' . $expires);
        }

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private static function parseCsv(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private static function parseExpires(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }
}
