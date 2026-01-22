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
 * Scaffold Middleware Command
 *
 * Generates a new route middleware class implementing RouteMiddleware interface.
 *
 * @package Glueful\Console\Commands\Scaffold
 */
#[AsCommand(
    name: 'scaffold:middleware',
    description: 'Scaffold a new route middleware class'
)]
class MiddlewareCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setDescription('Scaffold a new route middleware class')
            ->setHelp($this->getDetailedHelp())
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The name of the middleware class (e.g., RateLimitMiddleware)'
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
                'Custom path for the middleware file',
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

        // Normalize the name
        $name = $this->normalizeMiddlewareName($name);

        // Validate the name
        if (!$this->isValidClassName($name)) {
            $this->error("Invalid middleware name: {$name}");
            $this->line('Class names must be PascalCase and contain only letters and numbers.');
            return self::FAILURE;
        }

        // Determine the file path
        $basePath = $customPath ?? $this->getDefaultMiddlewarePath();
        $filePath = $this->buildFilePath($basePath, $name);

        // Check if file exists
        if (file_exists($filePath) && !$force) {
            $this->error("Middleware already exists: {$filePath}");
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
        $content = $this->generateMiddlewareClass($name);

        // Write the file
        if (file_put_contents($filePath, $content) === false) {
            $this->error("Failed to write file: {$filePath}");
            return self::FAILURE;
        }

        $this->success("Middleware scaffolded successfully!");
        $this->line("File: {$filePath}");
        $this->line('');
        $this->info('Next steps:');
        $this->line('1. Implement your middleware logic in the handle() method');
        $this->line('2. Register the middleware in your service provider');
        $this->line('3. Apply the middleware to your routes');
        $this->line('');
        $this->line('Example registration:');
        $className = $this->extractClassName($name);
        $alias = $this->toSnakeCase($this->stripSuffix($className, 'Middleware'));
        $this->line("  \$container->set('{$alias}', {$className}::class);");
        $this->line('');
        $this->line('Example route usage:');
        $this->line("  \$router->get('/protected', \$handler)->middleware(['{$alias}']);");

        return self::SUCCESS;
    }

    /**
     * Normalize the middleware name
     */
    private function normalizeMiddlewareName(string $name): string
    {
        // Remove .php extension if provided
        $name = preg_replace('/\.php$/', '', $name) ?? $name;

        // Ensure PascalCase for each path segment
        $parts = explode('/', str_replace('\\', '/', $name));
        $parts = array_map(fn($part) => ucfirst($part), $parts);
        $name = implode('/', $parts);

        // Add Middleware suffix if not present
        if (!str_ends_with($name, 'Middleware')) {
            $name .= 'Middleware';
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
     * Get the default path for middleware files
     */
    private function getDefaultMiddlewarePath(): string
    {
        // Check if we're in an app context or framework context
        $appPath = base_path('app/Http/Middleware');
        $srcPath = base_path('src/Http/Middleware');

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
        // Handle nested namespaces (e.g., Admin/AuthMiddleware)
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
     * Strip a suffix from a string
     */
    private function stripSuffix(string $value, string $suffix): string
    {
        if (str_ends_with($value, $suffix)) {
            return substr($value, 0, -strlen($suffix));
        }
        return $value;
    }

    /**
     * Convert PascalCase to snake_case
     */
    private function toSnakeCase(string $value): string
    {
        $result = preg_replace('/([a-z])([A-Z])/', '$1_$2', $value);
        return strtolower($result ?? $value);
    }

    /**
     * Generate the middleware class content
     */
    private function generateMiddlewareClass(string $name): string
    {
        $className = $this->extractClassName($name);
        $namespace = $this->buildNamespace($name);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * {$className}
 *
 * Route middleware for processing HTTP requests.
 *
 * @package {$namespace}
 */
class {$className} implements RouteMiddleware
{
    /**
     * Handle the incoming request
     *
     * @param Request \$request The incoming HTTP request
     * @param callable \$next The next middleware in the pipeline
     * @param mixed ...\$params Runtime parameters passed to the middleware
     * @return mixed Response or data to be normalized
     */
    public function handle(Request \$request, callable \$next, mixed ...\$params): mixed
    {
        // Pre-processing logic here
        // Example: Check authentication, validate headers, etc.

        // Call the next middleware in the pipeline
        \$response = \$next(\$request);

        // Post-processing logic here
        // Example: Add headers, modify response, etc.

        return \$response;
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
            ? 'App\\Http\\Middleware'
            : 'Glueful\\Http\\Middleware';

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
Scaffold a new route middleware class implementing RouteMiddleware.

Middleware classes intercept HTTP requests before they reach your controller,
allowing you to perform authentication, logging, rate limiting, and more.

Examples:
  php glueful scaffold:middleware AuthMiddleware
  php glueful scaffold:middleware Admin/RoleCheckMiddleware
  php glueful scaffold:middleware CacheMiddleware --force

The generated class will be placed in app/Http/Middleware/ (or src/Http/Middleware/
for framework development).

Features:
  - Implements RouteMiddleware interface
  - Pre/post-processing hooks
  - Runtime parameter support
  - Pipeline-based execution

Registration:
  Register your middleware in a service provider:

  \$container->set('auth', AuthMiddleware::class);

Usage in routes:
  \$router->get('/dashboard', [DashboardController::class, 'index'])
      ->middleware(['auth']);

  \$router->group(['middleware' => ['auth', 'admin']], function (\$router) {
      \$router->get('/admin', [AdminController::class, 'index']);
  });

Middleware with parameters:
  \$router->get('/api', \$handler)->middleware(['rate_limit:100,60']);
HELP;
    }
}
