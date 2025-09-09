# Phase 4: Extension System Compatibility

## Overview

This phase ensures the extension system works seamlessly in the new package architecture, supporting both local extensions and Composer-distributed extensions.

## Step 4.1: Framework Extension Manager Update

The framework's extension manager should be able to load extensions from multiple sources:

**Enhanced Extension Manager:**
```php
// glueful-framework/src/Extensions/ExtensionManager.php
<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\DI\Container;
use Composer\Autoload\ClassLoader;

class ExtensionManager
{
    private Container $container;
    private array $loadedExtensions = [];
    private array $extensionPaths = [];
    
    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->initializeExtensionPaths();
    }
    
    public function loadEnabledExtensions(): void
    {
        $enabledExtensions = $this->getEnabledExtensions();
        
        foreach ($enabledExtensions as $extensionName) {
            try {
                $this->loadExtension($extensionName);
            } catch (\Throwable $e) {
                $this->container->get('logger')->error("Failed to load extension: {$extensionName}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
    }
    
    public function loadExtension(string $extensionName): void
    {
        if (isset($this->loadedExtensions[$extensionName])) {
            return; // Already loaded
        }
        
        $extensionClass = $this->findExtensionClass($extensionName);
        if (!$extensionClass) {
            throw new ExtensionNotFoundException("Extension class not found for: {$extensionName}");
        }
        
        if (!class_exists($extensionClass)) {
            throw new ExtensionNotFoundException("Extension class does not exist: {$extensionClass}");
        }
        
        $extension = new $extensionClass($this->container);
        
        if (!$extension instanceof BaseExtension) {
            throw new InvalidExtensionException("Extension must extend BaseExtension: {$extensionClass}");
        }
        
        // Load extension dependencies
        $this->loadExtensionDependencies($extension->getDependencies());
        
        // Initialize the extension
        $extension->boot();
        $extension->register();
        
        $this->loadedExtensions[$extensionName] = $extension;
        
        $this->container->get('logger')->info("Extension loaded successfully: {$extensionName}");
    }
    
    private function initializeExtensionPaths(): void
    {
        // Local extensions directory (application-level)
        $localExtensionsPath = $this->container->getParameter('app.base_path') . '/extensions';
        if (is_dir($localExtensionsPath)) {
            $this->extensionPaths['local'] = $localExtensionsPath;
        }
        
        // Composer packages (vendor directory)
        $this->discoverComposerExtensions();
    }
    
    private function discoverComposerExtensions(): void
    {
        $composerFile = $this->container->getParameter('app.base_path') . '/vendor/composer/installed.json';
        
        if (!file_exists($composerFile)) {
            return;
        }
        
        $installed = json_decode(file_get_contents($composerFile), true);
        $packages = $installed['packages'] ?? $installed; // Support both formats
        
        foreach ($packages as $package) {
            if (($package['type'] ?? '') === 'glueful-extension') {
                $packageName = $package['name'];
                $extensionClass = $package['extra']['glueful']['extension-class'] ?? null;
                
                if ($extensionClass) {
                    $this->extensionPaths['composer'][$packageName] = $extensionClass;
                }
            }
        }
    }
    
    private function findExtensionClass(string $extensionName): ?string
    {
        // Check local extensions first
        if (isset($this->extensionPaths['local'])) {
            $localExtensionPath = $this->extensionPaths['local'] . '/' . $extensionName . '/Extension.php';
            if (file_exists($localExtensionPath)) {
                require_once $localExtensionPath;
                
                // Try common namespace patterns
                $possibleClasses = [
                    "App\\Extensions\\{$extensionName}\\Extension",
                    "Extensions\\{$extensionName}\\Extension",
                    "{$extensionName}\\Extension"
                ];
                
                foreach ($possibleClasses as $class) {
                    if (class_exists($class)) {
                        return $class;
                    }
                }
            }
        }
        
        // Check Composer extensions
        if (isset($this->extensionPaths['composer'])) {
            foreach ($this->extensionPaths['composer'] as $packageName => $extensionClass) {
                if (str_contains($packageName, strtolower($extensionName)) || 
                    str_contains(strtolower($extensionClass), strtolower($extensionName))) {
                    return $extensionClass;
                }
            }
        }
        
        return null;
    }
    
    private function getEnabledExtensions(): array
    {
        $configPath = $this->container->getParameter('app.base_path') . '/config/extensions.php';
        
        if (!file_exists($configPath)) {
            return [];
        }
        
        $config = require $configPath;
        return array_keys(array_filter($config, function($enabled) {
            return $enabled === true;
        }));
    }
    
    private function loadExtensionDependencies(array $dependencies): void
    {
        foreach ($dependencies as $dependency) {
            if (!isset($this->loadedExtensions[$dependency])) {
                $this->loadExtension($dependency);
            }
        }
    }
    
    public function getLoadedExtensions(): array
    {
        return $this->loadedExtensions;
    }
    
    public function isExtensionLoaded(string $extensionName): bool
    {
        return isset($this->loadedExtensions[$extensionName]);
    }
}
```

## Step 4.2: Composer Extension Package Format

Extensions can now be distributed as Composer packages with proper metadata:

**Extension Package Structure:**
```json
{
    "name": "glueful/rbac-extension",
    "description": "Role-Based Access Control extension for Glueful Framework",
    "type": "glueful-extension",
    "keywords": ["glueful", "extension", "rbac", "authorization"],
    "license": "MIT",
    "authors": [
        {
            "name": "Extension Author",
            "email": "author@example.com"
        }
    ],
    "require": {
        "php": "^8.2",
        "glueful/framework": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "Glueful\\Extensions\\RBAC\\": "src/"
        }
    },
    "extra": {
        "glueful": {
            "extension-class": "Glueful\\Extensions\\RBAC\\RBACExtension",
            "extension-version": "1.0.0",
            "extension-dependencies": [],
            "extension-config": "config/rbac.php"
        }
    }
}
```

**Extension Implementation:**
```php
<?php
// vendor/glueful/rbac-extension/src/RBACExtension.php

declare(strict_types=1);

namespace Glueful\Extensions\RBAC;

use Glueful\Extensions\BaseExtension;
use Glueful\Extensions\RBAC\Services\RoleService;
use Glueful\Extensions\RBAC\Services\PermissionService;
use Glueful\Extensions\RBAC\Middleware\RBACMiddleware;

class RBACExtension extends BaseExtension
{
    public function getName(): string
    {
        return 'RBAC';
    }
    
    public function getVersion(): string
    {
        return '1.0.0';
    }
    
    public function getDependencies(): array
    {
        return []; // No dependencies
    }
    
    public function boot(): void
    {
        // Register services
        $this->container->singleton(RoleService::class);
        $this->container->singleton(PermissionService::class);
        
        // Register middleware
        $this->container->singleton('middleware.rbac', RBACMiddleware::class);
        
        // Load configuration
        $this->loadConfig(__DIR__ . '/../config/rbac.php');
    }
    
    public function register(): void
    {
        // Register routes if needed
        $this->loadRoutes(__DIR__ . '/../routes/rbac.php');
        
        // Register database migrations
        $this->loadMigrations(__DIR__ . '/../database/migrations');
        
        // Register event listeners
        $this->addEventListener('user.created', [$this, 'handleUserCreated']);
    }
    
    public function handleUserCreated($event): void
    {
        $roleService = $this->container->get(RoleService::class);
        $roleService->assignDefaultRole($event->getUser());
    }
    
    protected function loadConfig(string $configPath): void
    {
        if (file_exists($configPath)) {
            $config = require $configPath;
            $this->container->setParameter('rbac', $config);
        }
    }
    
    protected function loadRoutes(string $routesPath): void
    {
        if (file_exists($routesPath)) {
            require $routesPath;
        }
    }
    
    protected function loadMigrations(string $migrationsPath): void
    {
        if (is_dir($migrationsPath)) {
            $migrationManager = $this->container->get('migration.manager');
            $migrationManager->addMigrationPath($migrationsPath);
        }
    }
}
```

## Step 4.3: Local Extensions Support

Support for local extensions in the application skeleton:

**Local Extension Structure:**
```
my-api/extensions/
├── CustomAuth/
│   ├── Extension.php
│   ├── config.php
│   ├── routes.php
│   └── Services/
│       └── CustomAuthService.php
└── Analytics/
    ├── Extension.php
    ├── config.php
    └── Controllers/
        └── AnalyticsController.php
```

**Local Extension Implementation:**
```php
<?php
// my-api/extensions/CustomAuth/Extension.php

declare(strict_types=1);

namespace App\Extensions\CustomAuth;

use Glueful\Extensions\BaseExtension;

class Extension extends BaseExtension
{
    public function getName(): string
    {
        return 'CustomAuth';
    }
    
    public function getVersion(): string
    {
        return '1.0.0';
    }
    
    public function getDependencies(): array
    {
        return [];
    }
    
    public function boot(): void
    {
        // Register custom authentication service
        $this->container->singleton(Services\CustomAuthService::class);
        
        // Load extension configuration
        $config = require __DIR__ . '/config.php';
        $this->container->setParameter('custom_auth', $config);
    }
    
    public function register(): void
    {
        // Load custom routes
        require __DIR__ . '/routes.php';
        
        // Register authentication provider
        $authManager = $this->container->get('auth.manager');
        $authManager->addProvider('custom', Services\CustomAuthService::class);
    }
}
```

## Step 4.4: Extension Configuration System

Implement a configuration system for extensions:

**Extension Configuration Manager:**
```php
<?php
// glueful-framework/src/Extensions/ExtensionConfigManager.php

declare(strict_types=1);

namespace Glueful\Extensions;

class ExtensionConfigManager
{
    private array $extensionConfigs = [];
    
    public function __construct(private string $configPath)
    {
        $this->loadExtensionConfigs();
    }
    
    public function isExtensionEnabled(string $extensionName): bool
    {
        return $this->extensionConfigs[$extensionName]['enabled'] ?? false;
    }
    
    public function getExtensionConfig(string $extensionName): array
    {
        return $this->extensionConfigs[$extensionName] ?? [];
    }
    
    public function setExtensionEnabled(string $extensionName, bool $enabled): void
    {
        $this->extensionConfigs[$extensionName]['enabled'] = $enabled;
        $this->saveExtensionConfigs();
    }
    
    public function setExtensionConfig(string $extensionName, array $config): void
    {
        $this->extensionConfigs[$extensionName] = array_merge(
            $this->extensionConfigs[$extensionName] ?? [],
            $config
        );
        $this->saveExtensionConfigs();
    }
    
    private function loadExtensionConfigs(): void
    {
        $configFile = $this->configPath . '/extensions.php';
        
        if (file_exists($configFile)) {
            $this->extensionConfigs = require $configFile;
        }
    }
    
    private function saveExtensionConfigs(): void
    {
        $configFile = $this->configPath . '/extensions.php';
        $configDir = dirname($configFile);
        
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }
        
        $content = "<?php\n\nreturn " . var_export($this->extensionConfigs, true) . ";\n";
        file_put_contents($configFile, $content, LOCK_EX);
    }
}
```

## Step 4.5: Extension CLI Commands

Create CLI commands to manage extensions:

**Extension Commands:**
```php
<?php
// glueful-framework/src/Commands/ExtensionCommands.php

declare(strict_types=1);

namespace Glueful\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ExtensionConfigManager;

class ExtensionListCommand extends Command
{
    protected static $defaultName = 'extensions:list';
    
    public function __construct(
        private ExtensionManager $extensionManager,
        private ExtensionConfigManager $configManager
    ) {
        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this->setDescription('List all available extensions');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Available Extensions:</info>');
        $output->writeln('');
        
        $loadedExtensions = $this->extensionManager->getLoadedExtensions();
        
        foreach ($loadedExtensions as $name => $extension) {
            $status = $this->configManager->isExtensionEnabled($name) ? 'Enabled' : 'Disabled';
            $output->writeln(sprintf(
                '  <comment>%s</comment> - %s (v%s)',
                $name,
                $status,
                $extension->getVersion()
            ));
        }
        
        return Command::SUCCESS;
    }
}

class ExtensionEnableCommand extends Command
{
    protected static $defaultName = 'extensions:enable';
    
    public function __construct(private ExtensionConfigManager $configManager)
    {
        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this->setDescription('Enable an extension')
            ->addArgument('name', InputArgument::REQUIRED, 'Extension name');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $extensionName = $input->getArgument('name');
        
        $this->configManager->setExtensionEnabled($extensionName, true);
        
        $output->writeln(sprintf('<info>Extension "%s" enabled successfully</info>', $extensionName));
        $output->writeln('<comment>Run "php glueful cache:clear" to refresh the application</comment>');
        
        return Command::SUCCESS;
    }
}

class ExtensionDisableCommand extends Command
{
    protected static $defaultName = 'extensions:disable';
    
    public function __construct(private ExtensionConfigManager $configManager)
    {
        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this->setDescription('Disable an extension')
            ->addArgument('name', InputArgument::REQUIRED, 'Extension name');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $extensionName = $input->getArgument('name');
        
        $this->configManager->setExtensionEnabled($extensionName, false);
        
        $output->writeln(sprintf('<info>Extension "%s" disabled successfully</info>', $extensionName));
        $output->writeln('<comment>Run "php glueful cache:clear" to refresh the application</comment>');
        
        return Command::SUCCESS;
    }
}
```


## Success Criteria

- [ ] Extension manager loads extensions from multiple sources (local, Composer)
- [ ] Composer packages can be distributed as extensions
- [ ] Local extensions work in application skeleton
- [ ] Extension configuration system manages enabled/disabled state
- [ ] CLI commands for extension management work correctly
- [ ] Extension dependencies are resolved and loaded in correct order
- [ ] Extension discovery works for both development and production

## Next Steps

After completing Phase 4, proceed to [Phase 5: Testing and Validation](PHASE_5_TESTING_VALIDATION.md) to ensure all components work together correctly in the new package architecture.