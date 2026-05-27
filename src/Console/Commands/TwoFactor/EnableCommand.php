<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\TwoFactor;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Glueful\Database\Connection;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: '2fa:enable', description: 'Force-enable email 2FA for a user (admin; skips PIN challenge)')]
final class EnableCommand extends BaseCommand
{
    public function __construct(?ContainerInterface $container = null, ?ApplicationContext $context = null)
    {
        parent::__construct($container, $context);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Force-enable email 2FA for a user (admin; skips the email PIN confirmation)')
            ->addArgument('user', InputArgument::REQUIRED, 'User UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uuid = (string) $input->getArgument('user');
        /** @var Connection $db */
        $db = $this->getServiceDynamic('database');

        $affected = $db->table('users')->where('uuid', $uuid)->update(['two_factor_enabled' => true]);
        if ($affected === 0) {
            $output->writeln("<error>No user found with uuid {$uuid}</error>");
            return self::FAILURE;
        }

        $output->writeln("<info>2FA enabled for {$uuid}.</info>");
        $output->writeln(
            '<comment>This bypasses the email-confirmation step — ensure the user can receive email.</comment>'
        );
        return self::SUCCESS;
    }
}
