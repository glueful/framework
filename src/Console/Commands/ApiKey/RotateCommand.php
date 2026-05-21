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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'apikey:rotate', description: 'Rotate an API key with a grace period')]
final class RotateCommand extends BaseCommand
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
            ->setDescription('Rotate an API key — creates a new key, sets old key to expire after the grace period')
            ->addArgument('uuid', InputArgument::REQUIRED, 'UUID of the key to rotate')
            ->addOption('grace', null, InputOption::VALUE_REQUIRED, 'Grace period in hours (default 24)', '24');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uuid = $input->getArgument('uuid');
        if (!is_string($uuid) || $uuid === '') {
            $output->writeln('<error>UUID argument is required</error>');
            return self::FAILURE;
        }

        $grace = (int) $input->getOption('grace');
        if ($grace <= 0) {
            $output->writeln('<error>--grace must be a positive integer (hours)</error>');
            return self::FAILURE;
        }

        $existing = ApiKey::query($this->context)
            ->where('uuid', '=', $uuid)
            ->first();
        if (!$existing instanceof ApiKey) {
            $output->writeln('<error>No API key found with UUID: ' . $uuid . '</error>');
            return self::FAILURE;
        }

        $rotation = ApiKeyService::rotate($this->context, $existing, $grace);

        $output->writeln('');
        $output->writeln('┌──────────────────────────────────────────────────────────────┐');
        $output->writeln('│  New API key — SAVE THIS NOW. It will not be shown again.     │');
        $output->writeln('├──────────────────────────────────────────────────────────────┤');
        $output->writeln('│  ' . $rotation['new_plain']);
        $output->writeln('└──────────────────────────────────────────────────────────────┘');
        $output->writeln('Old key UUID:    ' . $rotation['old_uuid']);
        $output->writeln('Old key expires: ' . $rotation['old_expires_at']);
        $output->writeln('Both keys are valid during the grace window.');

        return self::SUCCESS;
    }
}
