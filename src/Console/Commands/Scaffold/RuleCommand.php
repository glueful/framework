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
 * Scaffold Rule Command
 *
 * Generates a new validation rule class implementing the Rule interface.
 *
 * @package Glueful\Console\Commands\Scaffold
 */
#[AsCommand(
    name: 'scaffold:rule',
    description: 'Scaffold a new validation rule class'
)]
class RuleCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setDescription('Scaffold a new validation rule class')
            ->setHelp($this->getDetailedHelp())
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The name of the rule class (e.g., UniqueEmail)'
            )
            ->addOption(
                'params',
                null,
                InputOption::VALUE_OPTIONAL,
                'Comma-separated constructor parameters (e.g., minLength,maxLength)',
                null
            )
            ->addOption(
                'implicit',
                'i',
                InputOption::VALUE_NONE,
                'Make the rule implicit (validates even when field is empty)'
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
                'Custom path for the rule file',
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

        // Get rule options
        /** @var string|null $paramsOption */
        $paramsOption = $input->getOption('params');
        $params = $paramsOption !== null ? array_filter(array_map('trim', explode(',', $paramsOption))) : [];
        /** @var bool $implicit */
        $implicit = (bool) $input->getOption('implicit');

        // Normalize the name
        $name = $this->normalizeRuleName($name);

        // Validate the name
        if (!$this->isValidClassName($name)) {
            $this->error("Invalid rule name: {$name}");
            $this->line('Class names must be PascalCase and contain only letters and numbers.');
            return self::FAILURE;
        }

        // Determine the file path
        $basePath = $customPath ?? $this->getDefaultRulePath();
        $filePath = $this->buildFilePath($basePath, $name);

        // Check if file exists
        if (file_exists($filePath) && !$force) {
            $this->error("Rule already exists: {$filePath}");
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
        $content = $this->generateRuleClass($name, $params, $implicit);

        // Write the file
        if (file_put_contents($filePath, $content) === false) {
            $this->error("Failed to write file: {$filePath}");
            return self::FAILURE;
        }

        $this->success("Validation rule scaffolded successfully!");
        $this->line("File: {$filePath}");
        $this->line('');

        $className = $this->extractClassName($name);

        if ($params !== []) {
            $this->line('Constructor parameters:');
            foreach ($params as $param) {
                $this->line("  - \${$param}");
            }
            $this->line('');
        }

        $this->info('Next steps:');
        $this->line('1. Implement your validation logic in the validate() method');
        $this->line('2. Return null for valid values, or an error message string for invalid values');
        $this->line('3. Use the rule in your FormRequest or validation calls');
        $this->line('');
        $this->line('Example usage in FormRequest:');

        if ($params !== []) {
            $paramValues = array_fill(0, count($params), "'value'");
            $this->line("  'field' => [new {$className}(" . implode(', ', $paramValues) . ")]");
        } else {
            $this->line("  'field' => [new {$className}()]");
        }

        return self::SUCCESS;
    }

    /**
     * Normalize the rule name
     */
    private function normalizeRuleName(string $name): string
    {
        // Remove .php extension if provided
        $name = preg_replace('/\.php$/', '', $name) ?? $name;

        // Ensure PascalCase for each path segment
        $parts = explode('/', str_replace('\\', '/', $name));
        $parts = array_map(fn($part) => ucfirst($part), $parts);
        $name = implode('/', $parts);

        // Don't add "Rule" suffix - keep names clean like "UniqueEmail", "PasswordStrength"
        return $name;
    }

    /**
     * Validate the class name
     */
    private function isValidClassName(string $name): bool
    {
        $parts = explode('/', str_replace('\\', '/', $name));

        foreach ($parts as $part) {
            if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the default path for rule files
     */
    private function getDefaultRulePath(): string
    {
        // Check if we're in an app context or framework context
        $appPath = base_path('app/Validation/Rules');
        $srcPath = base_path('src/Validation/Rules');

        if (is_dir(base_path('app'))) {
            return $appPath;
        }

        return $srcPath;
    }

    /**
     * Build the full file path from the base path and name
     */
    private function buildFilePath(string $basePath, string $name): string
    {
        // Handle nested namespaces (e.g., Password/Strength)
        $name = str_replace('\\', '/', $name);

        return rtrim($basePath, '/') . '/' . $name . '.php';
    }

    /**
     * Extract class name from potentially nested path
     */
    private function extractClassName(string $name): string
    {
        $parts = explode('/', str_replace('\\', '/', $name));
        return end($parts);
    }

    /**
     * Generate the rule class content
     *
     * @param string $name Rule name
     * @param array<int, string> $params Constructor parameters
     * @param bool $implicit Whether the rule is implicit
     */
    private function generateRuleClass(string $name, array $params, bool $implicit): string
    {
        $className = $this->extractClassName($name);
        $namespace = $this->buildNamespace($name);

        $implicitInterface = $implicit ? ', ImplicitRule' : '';
        $implicitImport = $implicit ? "use Glueful\\Validation\\Contracts\\ImplicitRule;\n" : '';

        $constructorParams = $this->generateConstructorParams($params);
        $constructorDoc = $this->generateConstructorDoc($params);
        $propertyUsage = $this->generatePropertyUsage($params);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Glueful\Validation\Contracts\Rule;
{$implicitImport}
/**
 * {$className} Validation Rule
 *
 * Custom validation rule for field validation.
 *
 * @package {$namespace}
 */
class {$className} implements Rule{$implicitInterface}
{
{$constructorDoc}    public function __construct({$constructorParams})
    {
        //
    }

    /**
     * Validate the given value
     *
     * @param mixed \$value The value to validate
     * @param array<string, mixed> \$context Validation context containing:
     *                                       - 'field': The field name being validated
     *                                       - 'data': All data being validated
     *                                       - 'attribute': Human-readable field name
     * @return string|null Error message if validation fails, null if valid
     */
    public function validate(mixed \$value, array \$context = []): ?string
    {
        \$field = \$context['field'] ?? 'field';
        \$attribute = \$context['attribute'] ?? \$field;

        // Implement your validation logic here
        // Return null if valid, or an error message if invalid
        //
        // Example:
        // if (empty(\$value)) {
        //     return "The {\$attribute} field is required.";
        // }
{$propertyUsage}
        // Validation passed
        return null;
    }
}

PHP;
    }

    /**
     * Generate constructor parameters
     *
     * @param array<int, string> $params
     */
    private function generateConstructorParams(array $params): string
    {
        if ($params === []) {
            return '';
        }

        $parts = [];
        foreach ($params as $param) {
            $camelParam = $this->toCamelCase($param);
            $parts[] = "private mixed \${$camelParam} = null";
        }

        return "\n        " . implode(",\n        ", $parts) . "\n    ";
    }

    /**
     * Generate constructor documentation
     *
     * @param array<int, string> $params
     */
    private function generateConstructorDoc(array $params): string
    {
        if ($params === []) {
            return "    /**\n     * Create a new rule instance\n     */\n";
        }

        $lines = [
            "    /**",
            "     * Create a new rule instance",
            "     *",
        ];

        foreach ($params as $param) {
            $camelParam = $this->toCamelCase($param);
            $lines[] = "     * @param mixed \${$camelParam} Rule parameter";
        }

        $lines[] = "     */";

        return implode("\n", $lines) . "\n";
    }

    /**
     * Generate example property usage in validate method
     *
     * @param array<int, string> $params
     */
    private function generatePropertyUsage(array $params): string
    {
        if ($params === []) {
            return '';
        }

        $lines = ["\n        // You can access constructor parameters:"];
        foreach ($params as $param) {
            $camelParam = $this->toCamelCase($param);
            $lines[] = "        // \$this->{$camelParam}";
        }
        $lines[] = "";

        return implode("\n", $lines);
    }

    /**
     * Convert to camelCase
     */
    private function toCamelCase(string $value): string
    {
        $value = str_replace(['-', '_'], ' ', $value);
        $value = ucwords($value);
        $value = str_replace(' ', '', $value);
        return lcfirst($value);
    }

    /**
     * Build the namespace from the class name
     */
    private function buildNamespace(string $name): string
    {
        // Check if we're in an app or framework context
        $baseNamespace = is_dir(base_path('app'))
            ? 'App\\Validation\\Rules'
            : 'Glueful\\Validation\\Rules';

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
Scaffold a new validation rule class implementing the Rule interface.

Custom validation rules allow you to encapsulate complex validation logic
in reusable, testable classes.

Examples:
  php glueful scaffold:rule UniqueEmail
  php glueful scaffold:rule PasswordStrength --params=minLength,requireNumbers
  php glueful scaffold:rule RequiredWithoutField --implicit
  php glueful scaffold:rule Domain/CustomRule --force

The generated class will be placed in app/Validation/Rules/ (or src/Validation/Rules/
for framework development).

Options:
  --params    Comma-separated constructor parameters
  --implicit  Make the rule validate even when field is empty

Features:
  - Implements Rule interface
  - Access to full validation context
  - Constructor parameters for configurable rules
  - Implicit rule support for required-like rules

Usage in FormRequest:
  public function rules(): array
  {
      return [
          'email' => ['required', 'email', new UniqueEmail()],
          'password' => [new PasswordStrength(minLength: 8, requireNumbers: true)],
      ];
  }

Direct validation:
  \$validator = new Validator(\$data, [
      'field' => [new CustomRule()],
  ]);

  if (\$validator->fails()) {
      \$errors = \$validator->errors();
  }
HELP;
    }
}
