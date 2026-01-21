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
 * Scaffold Resource Command
 *
 * Generates a new API Resource class for transforming data.
 *
 * @package Glueful\Console\Commands\Scaffold
 */
#[AsCommand(
    name: 'scaffold:resource',
    description: 'Scaffold a new API resource class'
)]
class ResourceCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setDescription('Scaffold a new API resource class')
            ->setHelp($this->getDetailedHelp())
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The name of the resource class (e.g., UserResource)'
            )
            ->addOption(
                'model',
                'm',
                InputOption::VALUE_NONE,
                'Generate a ModelResource with ORM integration'
            )
            ->addOption(
                'collection',
                'c',
                InputOption::VALUE_NONE,
                'Generate a ResourceCollection class'
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
                'Custom path for the resource file',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $name */
        $name = $input->getArgument('name');
        /** @var bool $force */
        $force = (bool) $input->getOption('force');
        /** @var bool $isModel */
        $isModel = (bool) $input->getOption('model');
        /** @var bool $isCollection */
        $isCollection = (bool) $input->getOption('collection');
        /** @var string|null $customPath */
        $customPath = $input->getOption('path');
        $customPath = is_string($customPath) ? $customPath : null;

        // Ensure proper suffix
        if ($isCollection) {
            if (!str_ends_with($name, 'Collection')) {
                $name .= 'Collection';
            }
        } elseif (!str_ends_with($name, 'Resource')) {
            $name .= 'Resource';
        }

        // Determine the file path
        $basePath = $customPath ?? $this->getDefaultResourcePath();
        $filePath = $this->buildFilePath($basePath, $name);

        // Check if file exists
        if (file_exists($filePath) && !$force) {
            $this->error("Resource already exists: {$filePath}");
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
        if ($isCollection) {
            $content = $this->generateCollectionClass($name);
        } elseif ($isModel) {
            $content = $this->generateModelResourceClass($name);
        } else {
            $content = $this->generateResourceClass($name);
        }

        // Write the file
        if (file_put_contents($filePath, $content) === false) {
            $this->error("Failed to write file: {$filePath}");
            return self::FAILURE;
        }

        $this->success("Resource scaffolded successfully!");
        $this->line("File: {$filePath}");
        $this->line('');
        $this->showNextSteps($name, $isCollection, $isModel);

        return self::SUCCESS;
    }

    /**
     * Get the default path for resource files
     */
    private function getDefaultResourcePath(): string
    {
        // Check if we're in an app context or framework context
        $appPath = base_path('app/Http/Resources');
        $srcPath = base_path('src/Http/Resources');

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
        // Handle nested namespaces (e.g., User/UserResource)
        $name = str_replace('\\', '/', $name);

        return rtrim($basePath, '/') . '/' . $name . '.php';
    }

    /**
     * Generate the basic resource class content
     */
    private function generateResourceClass(string $name): string
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

use Glueful\Http\Resources\JsonResource;

/**
 * {$className}
 *
 * API resource for transforming data into consistent JSON responses.
 */
class {$className} extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            // Define your transformation here
            // 'id' => \$this->resource['id'],
            // 'name' => \$this->resource['name'],
            // 'email' => \$this->resource['email'],
            // 'created_at' => \$this->resource['created_at'],
            //
            // Conditional attributes:
            // 'secret' => \$this->when(\$this->isAdmin(), \$this->resource['secret']),
            //
            // Conditional merging:
            // \$this->mergeWhen(\$this->isAdmin(), [
            //     'admin_notes' => \$this->resource['admin_notes'],
            // ]),
        ];
    }
}

PHP;
    }

    /**
     * Generate the model resource class content
     */
    private function generateModelResourceClass(string $name): string
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

use Glueful\Http\Resources\ModelResource;

/**
 * {$className}
 *
 * API resource for transforming ORM models into consistent JSON responses.
 * Provides ORM-specific helpers for relationships and attributes.
 */
class {$className} extends ModelResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            // Basic attributes
            // 'id' => \$this->attribute('uuid'),
            // 'name' => \$this->attribute('name'),
            // 'email' => \$this->attribute('email'),
            //
            // Date formatting
            // 'created_at' => \$this->dateAttribute('created_at'),
            // 'updated_at' => \$this->whenDateNotNull('updated_at'),
            //
            // Conditional relationships (only included if loaded)
            // 'posts' => \$this->whenLoaded('posts'),
            // 'profile' => \$this->relationshipResource('profile', ProfileResource::class),
            // 'comments' => \$this->relationshipCollection('comments', CommentResource::class),
            //
            // Relationship counts
            // 'posts_count' => \$this->whenCounted('posts'),
            //
            // Pivot data (for many-to-many relationships)
            // 'role' => \$this->whenPivotLoaded('role_user', 'role_name'),
        ];
    }
}

PHP;
    }

    /**
     * Generate the collection class content
     */
    private function generateCollectionClass(string $name): string
    {
        // Extract class name from potentially nested path
        $parts = explode('/', str_replace('\\', '/', $name));
        $className = end($parts);

        // Build namespace
        $namespace = $this->buildNamespace($name);

        // Determine the resource class name from the collection name
        $resourceClass = str_replace('Collection', 'Resource', $className);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Glueful\Http\Resources\ResourceCollection;

/**
 * {$className}
 *
 * Resource collection for transforming multiple items into consistent JSON responses.
 */
class {$className} extends ResourceCollection
{
    /**
     * The resource that this collection collects.
     *
     * @var class-string<\Glueful\Http\Resources\JsonResource<mixed>>
     */
    public string \$collects = {$resourceClass}::class;

    /**
     * Transform the collection into an array.
     *
     * Override this method to customize the collection transformation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => \$this->resolve(),
            // Add collection-level metadata
            // 'summary' => [
            //     'total_count' => \$this->count(),
            // ],
        ];
    }

    /**
     * Get additional data to include with the collection.
     *
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            // 'meta' => [
            //     'version' => '1.0',
            // ],
        ];
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
        $baseNamespace = is_dir(base_path('app'))
            ? 'App\\Http\\Resources'
            : 'Glueful\\Http\\Resources';

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
     * Show next steps after scaffolding
     */
    private function showNextSteps(string $name, bool $isCollection, bool $isModel): void
    {
        $this->info('Next steps:');

        if ($isCollection) {
            $this->line('1. Define the resource class this collection uses');
            $this->line('2. Optionally customize the toArray() method for collection-level data');
            $this->line('3. Use in your controller:');
            $this->line('');
            $this->line('Example usage:');
            $this->line("  return {$name}::make(\$users)->toResponse();");
            $this->line('');
            $this->line('With pagination:');
            $this->line("  return {$name}::make(\$users)");
            $this->line("      ->withPaginationFrom(\$paginationResult)");
            $this->line("      ->withLinks('/api/users')");
            $this->line("      ->toResponse();");
        } elseif ($isModel) {
            $this->line('1. Define your attribute transformations in toArray()');
            $this->line('2. Use ORM helpers like attribute(), whenLoaded(), dateAttribute()');
            $this->line('3. Use in your controller:');
            $this->line('');
            $this->line('Example usage:');
            $this->line("  return {$name}::make(\$model)->toResponse();");
            $this->line('');
            $this->line('With relationships:');
            $this->line("  \$model->load(['posts', 'profile']);");
            $this->line("  return {$name}::make(\$model)->toResponse();");
        } else {
            $this->line('1. Define your data transformations in toArray()');
            $this->line('2. Use conditional helpers like when(), mergeWhen()');
            $this->line('3. Use in your controller:');
            $this->line('');
            $this->line('Example usage:');
            $this->line("  return {$name}::make(\$data)->toResponse();");
            $this->line('');
            $this->line('Collection:');
            $this->line("  return {$name}::collection(\$items)->toResponse();");
        }
    }

    /**
     * Get detailed help text
     */
    private function getDetailedHelp(): string
    {
        return <<<HELP
Scaffold a new API Resource class for data transformation.

API Resources provide a consistent way to transform your data (models, arrays,
objects) into JSON responses with a standardized structure.

Examples:
  php glueful scaffold:resource UserResource
  php glueful scaffold:resource User/ProfileResource
  php glueful scaffold:resource UserResource --model
  php glueful scaffold:resource UserCollection --collection
  php glueful scaffold:resource PostResource --force

Resource Types:
  Default (JsonResource):
    - Basic resource for arrays and simple objects
    - Supports conditional attributes with when(), mergeWhen()
    - Use for non-ORM data sources

  Model (--model flag):
    - Extended resource for ORM models
    - ORM-specific helpers: attribute(), dateAttribute(), whenLoaded()
    - Relationship handling with relationshipResource(), relationshipCollection()
    - Pivot data access with whenPivotLoaded()

  Collection (--collection flag):
    - For transforming multiple items
    - Automatic pagination support
    - Collection-level metadata

Usage in controllers:
  // Single resource
  public function show(int \$id): Response
  {
      \$user = User::find(\$id);
      return UserResource::make(\$user)->toResponse();
  }

  // Collection with pagination
  public function index(): Response
  {
      \$result = User::query()->paginate(page: 1, perPage: 25);
      return UserResource::collection(\$result['data'])
          ->withPaginationFrom(\$result)
          ->toResponse();
  }
HELP;
    }
}
