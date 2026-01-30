<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Create Extension Command
 *
 * Create a new local extension with the standard structure.
 */
#[AsCommand(
    name: 'create:extension',
    description: 'Create new local extension'
)]
final class CreateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setDescription('Create new local extension')
            ->addArgument('name', InputArgument::REQUIRED, 'Extension name (e.g., blog, shop)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name));
        $className = ucfirst($slug);
        $namespace = "Extensions\\{$className}";

        $extensionsDir = base_path($this->getContext(), 'extensions');
        $extensionDir = $extensionsDir . '/' . $slug;

        if (is_dir($extensionDir)) {
            $output->writeln("<error>Extension '{$slug}' already exists!</error>");
            return self::FAILURE;
        }

        // Create directory structure
        @mkdir($extensionsDir, 0755, true);
        @mkdir($extensionDir, 0755, true);
        @mkdir($extensionDir . '/src', 0755, true);
        @mkdir($extensionDir . '/routes', 0755, true);
        @mkdir($extensionDir . '/config', 0755, true);
        @mkdir($extensionDir . '/database/migrations', 0755, true);

        // Create composer.json
        $composerJson = [
            'name' => "local/{$slug}",
            'description' => "Local {$name} extension",
            'type' => 'glueful-extension',
            'autoload' => [
                'psr-4' => [
                    "{$namespace}\\" => 'src/'
                ]
            ],
            'extra' => [
                'glueful' => [
                    'provider' => "{$namespace}\\{$className}ServiceProvider"
                ]
            ]
        ];

        file_put_contents(
            $extensionDir . '/composer.json',
            json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Create ServiceProvider
        $serviceProvider = $this->generateServiceProvider($namespace, $className, $name);
        file_put_contents($extensionDir . "/src/{$className}ServiceProvider.php", $serviceProvider);

        // Create basic routes file
        $routes = $this->generateRoutes($namespace, $className);
        file_put_contents($extensionDir . '/routes/routes.php', $routes);

        // Create config file
        $config = $this->generateConfig($slug);
        file_put_contents($extensionDir . "/config/{$slug}.php", $config);

        $output->writeln("<info>Extension '{$slug}' created successfully!</info>");
        $output->writeln("Location: {$extensionDir}");
        $output->writeln("");
        $output->writeln("To enable this extension, add it to config/extensions.php:");
        $output->writeln("  {$namespace}\\{$className}ServiceProvider::class,");

        return self::SUCCESS;
    }

    private function generateServiceProvider(string $namespace, string $className, string $name): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Glueful\Extensions\ServiceProvider;
use Glueful\Bootstrap\ApplicationContext;

class {$className}ServiceProvider extends ServiceProvider
{
    public function register(ApplicationContext \$context): void
    {
        // Register services here
        // \$this->mergeConfig('{$className}', require __DIR__.'/../config/{$className}.php');
    }
    
    public function boot(ApplicationContext \$context): void
    {
        // Load routes
        \$this->loadRoutesFrom(__DIR__.'/../routes/routes.php');
        
        // Load migrations
        \$this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        
        // Register extension metadata
        \$this->app->get(\Glueful\Extensions\ExtensionManager::class)->registerMeta(self::class, [
            'slug' => '{$className}',
            'name' => '{$name} Extension',
            'version' => '1.0.0',
            'description' => '{$name} functionality for Glueful',
        ]);
    }
}
PHP;
    }

    private function generateRoutes(string $namespace, string $className): string
    {
        $slug = strtolower($className);
        return <<<PHP
<?php

use Glueful\Routing\Router;

// {$className} extension routes
\$router->group(['prefix' => '{$slug}'], function (Router \$router) {
    \$router->get('/', function () {
        return response()->json(['message' => 'Hello from {$className} extension!']);
    });
});
PHP;
    }

    private function generateConfig(string $slug): string
    {
        return <<<PHP
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | {$slug} Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for the {$slug} extension.
    |
    */

    'enabled' => true,
    
    // Add your configuration options here
];
PHP;
    }
}
