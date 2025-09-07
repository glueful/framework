<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Extensions Enable Command
 *
 * Enable extension in development environment by editing config/extensions.php.
 * This is a development-only convenience command.
 */
#[AsCommand(
    name: 'extensions:enable',
    description: 'Enable extension (development only)'
)]
final class EnableCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setDescription('Enable extension (development only)')
            ->addArgument('extension', InputArgument::REQUIRED, 'Extension provider class or slug');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (env('APP_ENV') === 'production') {
            $output->writeln(
                '<error>This command is not available in production. Edit config/extensions.php directly.</error>'
            );
            return self::FAILURE;
        }

        $extension = (string) $input->getArgument('extension');

        $output->writeln('<comment>Development-only command</comment>');
        $output->writeln("To enable '{$extension}', add the following to config/extensions.php:");
        $output->writeln('');
        $output->writeln("    'enabled' => [");
        $output->writeln("        // existing entries...");
        $output->writeln("        {$extension}::class,");
        $output->writeln("    ],");
        $output->writeln('');
        $output->writeln(
            '<info>Note: In production, manage extensions through configuration files and deployment.</info>'
        );

        return self::SUCCESS;
    }
}
