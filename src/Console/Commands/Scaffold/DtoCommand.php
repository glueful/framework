<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Scaffold;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scaffold DTO Command
 *
 * Generates a new request or response DTO class.
 *
 * @package Glueful\Console\Commands\Scaffold
 */
#[AsCommand(
    name: 'scaffold:dto',
    description: 'Scaffold a new request or response DTO class'
)]
class DtoCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setDescription('Scaffold a new request or response DTO class')
            ->setHelp($this->getDetailedHelp())
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The name of the DTO class (e.g., CreatePostData)'
            )
            ->addOption(
                'response',
                'r',
                InputOption::VALUE_NONE,
                'Scaffold a response DTO instead of a request DTO'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Overwrite existing file if it exists'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $name */
        $name = $input->getArgument('name');
        /** @var bool $response */
        $response = (bool) $input->getOption('response');
        /** @var bool $force */
        $force = (bool) $input->getOption('force');

        if (preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $name) !== 1) {
            $this->error("Invalid DTO class name: {$name}");
            $this->line('Names must be valid PHP class identifiers.');
            return self::FAILURE;
        }

        $directory = $this->getTargetDirectory();
        $filePath = rtrim($directory, '/') . '/' . $name . '.php';

        if (file_exists($filePath) && !$force) {
            $this->warning("DTO already exists: {$filePath}");
            // In non-interactive mode confirm() returns the default (false), so
            // point the user at --force rather than failing opaquely.
            if (!$this->confirm('Overwrite the existing file?', false)) {
                $this->line('Aborted. Re-run with --force to overwrite.');
                return self::FAILURE;
            }
        }

        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                $this->error("Failed to create directory: {$directory}");
                return self::FAILURE;
            }
        }

        $namespace = $this->getTargetNamespace();
        $content = $response
            ? $this->generateResponseDto($namespace, $name)
            : $this->generateRequestDto($namespace, $name);

        if (file_put_contents($filePath, $content) === false) {
            $this->error("Failed to write file: {$filePath}");
            return self::FAILURE;
        }

        $type = $response ? 'Response' : 'Request';
        $this->success("{$type} DTO scaffolded successfully!");
        $this->line("File: {$filePath}");

        return self::SUCCESS;
    }

    /**
     * Resolve the directory the DTO should be written to.
     */
    private function getTargetDirectory(): string
    {
        if (is_dir(base_path($this->getContext(), 'app'))) {
            return base_path($this->getContext(), 'app/DTOs');
        }

        return base_path($this->getContext(), 'src/DTOs');
    }

    /**
     * Resolve the namespace for the generated DTO.
     */
    private function getTargetNamespace(): string
    {
        return is_dir(base_path($this->getContext(), 'app'))
            ? 'App\\DTOs'
            : 'Glueful\\DTOs';
    }

    /**
     * Generate the request DTO class content.
     */
    private function generateRequestDto(string $namespace, string $name): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\Attributes\Rule;

final class {$name} implements RequestData
{
    public function __construct(
        #[Rule('required|string|max:255')]
        public readonly string \$example,
    ) {
    }
}

PHP;
    }

    /**
     * Generate the response DTO class content.
     */
    private function generateResponseDto(string $namespace, string $name): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Glueful\Http\Contracts\ResponseData;

final class {$name} implements ResponseData
{
    public function __construct(
        public readonly string \$id,
        public readonly string \$example,
    ) {
    }
}

PHP;
    }

    /**
     * Get detailed help text.
     */
    private function getDetailedHelp(): string
    {
        return <<<HELP
Scaffold a new request or response DTO class.

By default a request DTO (implementing RequestData with #[Rule] attributes) is
generated. Use --response to scaffold a response DTO (implementing ResponseData).

Examples:
  php glueful scaffold:dto CreatePostData
  php glueful scaffold:dto PostData --response
  php glueful scaffold:dto CreatePostData --force

The generated class is placed in app/DTOs/ (or src/DTOs/ for framework
development).
HELP;
    }
}
