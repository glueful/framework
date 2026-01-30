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
 * Scaffold Filter Command
 *
 * Generates a new QueryFilter class for filtering API results.
 *
 * @package Glueful\Console\Commands\Scaffold
 */
#[AsCommand(
    name: 'scaffold:filter',
    description: 'Scaffold a new query filter class'
)]
class FilterCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setDescription('Scaffold a new query filter class')
            ->setHelp($this->getDetailedHelp())
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The name of the filter class (e.g., UserFilter)'
            )
            ->addOption(
                'filterable',
                null,
                InputOption::VALUE_OPTIONAL,
                'Comma-separated list of filterable fields',
                null
            )
            ->addOption(
                'sortable',
                null,
                InputOption::VALUE_OPTIONAL,
                'Comma-separated list of sortable fields',
                null
            )
            ->addOption(
                'searchable',
                null,
                InputOption::VALUE_OPTIONAL,
                'Comma-separated list of searchable fields',
                null
            )
            ->addOption(
                'model',
                'm',
                InputOption::VALUE_OPTIONAL,
                'Associated model class name',
                null
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
                'Custom path for the filter file',
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

        // Parse options
        $filterable = $this->parseOptionList($input->getOption('filterable'));
        $sortable = $this->parseOptionList($input->getOption('sortable'));
        $searchable = $this->parseOptionList($input->getOption('searchable'));
        $model = $input->getOption('model');
        $model = is_string($model) ? $model : null;

        // Normalize the name
        $name = $this->normalizeFilterName($name);

        // Validate the name
        if (!$this->isValidClassName($name)) {
            $this->error("Invalid filter name: {$name}");
            $this->line('Class names must be PascalCase and contain only letters and numbers.');
            return self::FAILURE;
        }

        // Determine the file path
        $basePath = $customPath ?? $this->getDefaultFilterPath();
        $filePath = $this->buildFilePath($basePath, $name);

        // Check if file exists
        if (file_exists($filePath) && !$force) {
            $this->error("Filter already exists: {$filePath}");
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
        $content = $this->generateFilterClass($name, $filterable, $sortable, $searchable, $model);

        // Write the file
        if (file_put_contents($filePath, $content) === false) {
            $this->error("Failed to write file: {$filePath}");
            return self::FAILURE;
        }

        $this->success("Filter scaffolded successfully!");
        $this->line("File: {$filePath}");
        $this->line('');
        $this->info('Next steps:');
        $this->line('1. Define filterable, sortable, and searchable fields');
        $this->line('2. Add custom filter methods if needed (e.g., filterStatus)');
        $this->line('3. Use the filter in your controller');
        $this->line('');
        $className = $this->extractClassName($name);
        $this->line('Example usage in controller:');
        $this->line("  public function index({$className} \$filter): Response");
        $this->line('  {');
        $this->line("      \$items = Model::query(\$this->getContext())");
        $this->line("          ->tap(fn(\$q) => \$filter->apply(\$q))");
        $this->line('          ->paginate();');
        $this->line('');
        $this->line('      return response(\$items);');
        $this->line('  }');

        return self::SUCCESS;
    }

    /**
     * Parse comma-separated option list
     *
     * @return array<string>
     */
    private function parseOptionList(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        return array_map('trim', explode(',', $value));
    }

    /**
     * Normalize the filter name
     */
    private function normalizeFilterName(string $name): string
    {
        // Remove .php extension if provided
        $name = preg_replace('/\.php$/', '', $name) ?? $name;

        // Ensure PascalCase for each path segment
        $parts = explode('/', str_replace('\\', '/', $name));
        $parts = array_map(fn($part) => ucfirst($part), $parts);
        $name = implode('/', $parts);

        // Add Filter suffix if not present
        if (!str_ends_with($name, 'Filter')) {
            $name .= 'Filter';
        }

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
     * Get the default path for filter files
     */
    private function getDefaultFilterPath(): string
    {
        $appPath = base_path($this->getContext(), 'app/Filters');
        $srcPath = base_path($this->getContext(), 'src/Filters');

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
     * Generate the filter class content
     *
     * @param array<string> $filterable
     * @param array<string> $sortable
     * @param array<string> $searchable
     */
    private function generateFilterClass(
        string $name,
        array $filterable,
        array $sortable,
        array $searchable,
        ?string $model
    ): string {
        $className = $this->extractClassName($name);
        $namespace = $this->buildNamespace($name);

        $filterableStr = $this->formatArrayProperty($filterable);
        $sortableStr = $this->formatArrayProperty($sortable);
        $searchableStr = $this->formatArrayProperty($searchable, false);

        $modelComment = $model !== null
            ? " * Filter for {$model} model.\n *\n"
            : '';

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Glueful\Api\Filtering\QueryFilter;

/**
 * {$className}
 *
{$modelComment} * Query filter for filtering, sorting, and searching API results.
 *
 * @package {$namespace}
 */
class {$className} extends QueryFilter
{
    /**
     * Fields allowed for filtering
     *
     * Set to null to allow all fields.
     *
     * @var array<string>|null
     */
    protected ?array \$filterable = {$filterableStr};

    /**
     * Fields allowed for sorting
     *
     * Set to null to allow all fields.
     *
     * @var array<string>|null
     */
    protected ?array \$sortable = {$sortableStr};

    /**
     * Fields included in full-text search
     *
     * @var array<string>
     */
    protected array \$searchable = {$searchableStr};

    /**
     * Default sort when none specified
     *
     * Use - prefix for descending (e.g., '-created_at')
     */
    protected ?string \$defaultSort = null;

    // Custom filter methods
    // Naming convention: filter{FieldName}
    //
    // Example:
    //
    // public function filterStatus(string|array \$value, string \$operator): void
    // {
    //     if (\$value === 'any') {
    //         return;
    //     }
    //
    //     if (is_array(\$value) || str_contains(\$value, ',')) {
    //         \$values = is_array(\$value) ? \$value : explode(',', \$value);
    //         \$this->query->whereIn('status', \$values);
    //     } else {
    //         \$this->query->where('status', \$value);
    //     }
    // }
}

PHP;
    }

    /**
     * Format array property for PHP code
     *
     * @param array<string> $items
     */
    private function formatArrayProperty(array $items, bool $nullable = true): string
    {
        if ($items === []) {
            return $nullable ? 'null' : '[]';
        }

        $formatted = array_map(fn($item) => "        '{$item}'", $items);
        return "[\n" . implode(",\n", $formatted) . ",\n    ]";
    }

    /**
     * Build the namespace from the class name
     */
    private function buildNamespace(string $name): string
    {
        $baseNamespace = is_dir(base_path($this->getContext(), 'app'))
            ? 'App\\Filters'
            : 'Glueful\\Filters';

        $parts = explode('/', str_replace('\\', '/', $name));
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
Scaffold a new query filter class extending QueryFilter.

Query filters provide a standardized way to filter, sort, and search API results
through URL query parameters.

Examples:
  php glueful scaffold:filter UserFilter
  php glueful scaffold:filter UserFilter --filterable=status,role --searchable=name,email
  php glueful scaffold:filter Post/PostFilter --model=Post
  php glueful scaffold:filter OrderFilter --sortable=created_at,total --force

The generated class will be placed in app/Filters/ (or src/Filters/
for framework development).

Features:
  - Field whitelisting for security
  - Custom filter methods
  - Full-text search
  - Multi-column sorting

Filter syntax examples:
  GET /users?filter[status]=active
  GET /users?filter[age][gte]=18
  GET /users?filter[status][in]=active,pending
  GET /users?sort=-created_at,name
  GET /users?search=john&search_fields=name,email

Custom filter method:
  public function filterStatus(string|array \$value, string \$operator): void
  {
      // Custom filtering logic
  }

Controller usage:
  public function index(UserFilter \$filter): Response
  {
      \$users = User::query(\$this->getContext())
          ->tap(fn(\$q) => \$filter->apply(\$q))
          ->paginate();

      return UserResource::collection(\$users);
  }
HELP;
    }
}
