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

#[AsCommand(name: '2fa:status', description: 'Show whether email 2FA is enabled for a user')]
final class StatusCommand extends BaseCommand
{
    public function __construct(?ContainerInterface $container = null, ?ApplicationContext $context = null)
    {
        parent::__construct($container, $context);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Show whether email 2FA is enabled for a user')
            ->addArgument('user', InputArgument::REQUIRED, 'User UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uuid = (string) $input->getArgument('user');
        /** @var Connection $db */
        $db = $this->getServiceDynamic('database');

        $row = $db->table('users')->select(['two_factor_enabled'])->where('uuid', $uuid)->first();
        if ($row === null) {
            $output->writeln("<error>No user found with uuid {$uuid}</error>");
            return self::FAILURE;
        }

        $value = $row['two_factor_enabled'] ?? false;
        $enabled = $value === true || $value === 1 || $value === '1';
        $output->writeln(sprintf('2FA for %s: <info>%s</info>', $uuid, $enabled ? 'enabled' : 'disabled'));
        return self::SUCCESS;
    }
}
