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
 * Scaffold Request Command
 *
 * Generates a new FormRequest class for request validation.
 *
 * @package Glueful\Console\Commands\Scaffold
 */
#[AsCommand(
    name: 'scaffold:request',
    description: 'Scaffold a new form request class'
)]
class RequestCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setDescription('Scaffold a new form request class')
            ->setHelp($this->getDetailedHelp())
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The name of the form request class (e.g., CreateUserRequest)'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Overwrite existing file if it exists'
            )
            ->addOption(
                'path',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Custom path for the request file',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $name */
        $name = $input->getArgument('name');
        /** @var bool $force */
        $force = (bool) $input->getOption('force');
        /** @var string|null $customPath */
        $customPath = $input->getOption('path');
        $customPath = is_string($customPath) ? $customPath : null;

        // Ensure name ends with 'Request'
        if (!str_ends_with($name, 'Request')) {
            $name .= 'Request';
        }

        // Determine the file path
        $basePath = $customPath ?? $this->getDefaultRequestPath();
        $filePath = $this->buildFilePath($basePath, $name);

        // Check if file exists
        if (file_exists($filePath) && !$force) {
            $this->error("Form request already exists: {$filePath}");
            $this->line('Use --force to overwrite.');
            return self::FAILURE;
        }

        // Create directory if it doesn't exist
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                $this->error("Failed to create directory: {$directory}");
                return self::FAILURE;
            }
        }

        // Generate the class content
        $content = $this->generateRequestClass($name);

        // Write the file
        if (file_put_contents($filePath, $content) === false) {
            $this->error("Failed to write file: {$filePath}");
            return self::FAILURE;
        }

        $this->success("Form request scaffolded successfully!");
        $this->line("File: {$filePath}");
        $this->line('');
        $this->info('Next steps:');
        $this->line('1. Define your validation rules in the rules() method');
        $this->line('2. Optionally add authorization logic in authorize()');
        $this->line('3. Type-hint the request in your controller method');
        $this->line('');
        $this->line('Example usage:');
        $this->line("  public function store({$name} \$request): Response");
        $this->line('  {');
        $this->line('      $data = $request->validated();');
        $this->line('  }');

        return self::SUCCESS;
    }

    /**
     * Get the default path for request files
     */
    private function getDefaultRequestPath(): string
    {
        // Check if we're in an app context or framework context
        $appPath = base_path($this->getContext(), 'app/Http/Requests');
        $srcPath = base_path($this->getContext(), 'src/Http/Requests');

        if (is_dir(base_path($this->getContext(), 'app'))) {
            return $appPath;
        }

        return $srcPath;
    }

    /**
     * Build the full file path from the base path and name
     */
    private function buildFilePath(string $basePath, string $name): string
    {
        // Handle nested namespaces (e.g., User/CreateUserRequest)
        $name = str_replace('\\', '/', $name);

        return rtrim($basePath, '/') . '/' . $name . '.php';
    }

    /**
     * Generate the request class content
     */
    private function generateRequestClass(string $name): string
    {
        // Extract class name from potentially nested path
        $parts = explode('/', str_replace('\\', '/', $name));
        $className = end($parts);

        // Build namespace
        $namespace = $this->buildNamespace($name);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Glueful\Validation\FormRequest;

/**
 * {$className}
 *
 * Form request for validating incoming request data.
 */
class {$className} extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, string|array<\Glueful\Validation\Contracts\Rule>>
     */
    public function rules(): array
    {
        return [
            // Define your validation rules here
            // 'email' => 'required|email',
            // 'password' => 'required|min:8|confirmed',
            // 'name' => 'required|string|max:255',
        ];
    }

    /**
     * Get custom error messages for validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // 'email.required' => 'We need your email address.',
            // 'email.email' => 'Please provide a valid email address.',
        ];
    }

    /**
     * Get custom attribute names for error messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            // 'email' => 'email address',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * Override to modify request data before validation.
     */
    protected function prepareForValidation(): void
    {
        // Example: Normalize email to lowercase
        // if (\$this->has('email')) {
        //     \$this->merge(['email' => strtolower(\$this->input('email'))]);
        // }
    }
}

PHP;
    }

    /**
     * Build the namespace from the class name
     */
    private function buildNamespace(string $name): string
    {
        // Check if we're in an app or framework context
        $baseNamespace = is_dir(base_path($this->getContext(), 'app'))
            ? 'App\\Http\\Requests'
            : 'Glueful\\Http\\Requests';

        // Handle nested paths
        $parts = explode('/', str_replace('\\', '/', $name));

        // Remove the class name from parts
        array_pop($parts);

        if ($parts !== []) {
            return $baseNamespace . '\\' . implode('\\', $parts);
        }

        return $baseNamespace;
    }

    /**
     * Get detailed help text
     */
    private function getDetailedHelp(): string
    {
        return <<<HELP
Scaffold a new FormRequest class for request validation.

FormRequest classes provide a clean way to encapsulate validation logic,
authorization checks, and input preparation in a single, testable class.

Examples:
  php glueful scaffold:request CreateUserRequest
  php glueful scaffold:request User/UpdateProfileRequest
  php glueful scaffold:request StorePostRequest --force

The generated class will be placed in app/Http/Requests/ (or src/Http/Requests/
for framework development).

Features:
  - Automatic validation in middleware pipeline
  - Authorization checks before validation
  - Custom error messages
  - Data preparation hooks
  - Type-safe validated data access

Usage in controllers:
  public function store(CreateUserRequest \$request): Response
  {
      // Validation and authorization already passed
      \$validatedData = \$request->validated();

      // Create user with validated data
      \$user = User::create(\$this->getContext(), \$validatedData);

      return Response::created(\$user);
  }
HELP;
    }
}
