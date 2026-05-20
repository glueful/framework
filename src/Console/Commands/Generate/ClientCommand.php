<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Generate;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Build an SDK client by shelling out to an OpenAPI generator.
 *
 * Glueful does not own codegen logic — this command picks the appropriate
 * off-the-shelf generator for the requested language and prints (or runs)
 * the shell command with Glueful-shaped defaults.
 */
#[AsCommand(
    name: 'generate:client',
    description: 'Generate an SDK client from openapi.json via an off-the-shelf OpenAPI generator',
)]
final class ClientCommand extends Command
{
    private const TS_TOOL = 'openapi-typescript';
    private const FALLBACK_TOOL = 'openapi-generator-cli generate';

    protected function configure(): void
    {
        $this
            ->addArgument(
                'language',
                InputArgument::REQUIRED,
                'Target language (typescript, ts, python, go, ruby, java, ...)',
            )
            ->addOption(
                'spec',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to openapi.json',
                'openapi.json',
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output directory',
                './generated',
            )
            ->addOption(
                'execute',
                null,
                InputOption::VALUE_NONE,
                'Run the command instead of printing it',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $language = (string) $input->getArgument('language');
        $spec = (string) $input->getOption('spec');
        $outDir = (string) $input->getOption('output');

        $shell = $this->buildShellCommand($language, $spec, $outDir);

        if ((bool) $input->getOption('execute')) {
            $output->writeln("<info>Running:</info> {$shell}");
            $status = 0;
            passthru($shell, $status);
            return $status;
        }

        $output->writeln($shell);
        $output->writeln('');
        $output->writeln('<comment>Add --execute to run this command directly.</comment>');
        return Command::SUCCESS;
    }

    public function buildShellCommand(string $language, string $specPath, string $outputDir): string
    {
        if ($language === 'typescript' || $language === 'ts') {
            $outFile = rtrim($outputDir, '/') . '/api.d.ts';
            return self::TS_TOOL . ' ' . escapeshellarg($specPath) . ' -o ' . $outFile;
        }

        // Language is restricted to alphanumerics + dashes — safe to inline.
        // Spec path and output dir are user-supplied — always escape.
        $safeLanguage = preg_replace('/[^a-zA-Z0-9_-]/', '', $language) ?? '';

        return self::FALLBACK_TOOL
            . ' -i ' . escapeshellarg($specPath)
            . ' -g ' . $safeLanguage
            . ' -o ' . escapeshellarg($outputDir);
    }
}
