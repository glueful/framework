# Glueful Extension System Migration - Phase 2

## Prerequisites

This plan should be executed **AFTER** the package architecture migration from `PACKAGE_MIGRATION_IMPLEMENTATION.md` is completed and stable.

## Executive Summary

Enhance Glueful's extension system to support both Composer-based packages and local development, creating a hybrid approach that maximizes developer flexibility while enabling a thriving extension ecosystem.

## Current Extension System Analysis

### Current State (Post Package Migration)
```
my-api/
├── vendor/
│   └── glueful/framework/  # Framework with extension manager
├── extensions/             # Local extensions directory
│   ├── RBAC/
│   ├── Admin/  
│   ├── SocialLogin/
│   ├── EmailNotification/
│   └── BeanstalkdQueue/
└── composer.json           # Could include extension packages
```

### Current Extension Structure (Already Good)
```
extensions/ExtensionName/
├── ExtensionName.php        # Main extension class
├── manifest.json           # Extension metadata
├── composer.json           # Dependencies (already exists!)
├── src/                    # Extension source code
│   ├── Controllers/
│   ├── Services/
│   ├── routes.php
│   └── config.php
├── assets/                 # Extension assets
├── migrations/             # Database migrations
└── README.md
```

## Target Architecture: Hybrid Extension System

### Option 1: Composer Package Extensions
```bash
# Install published extensions
composer require glueful/rbac-extension
composer require glueful/social-login-extension
composer require community/custom-auth-extension
```

Extensions installed in:
```
vendor/
├── glueful/rbac-extension/
├── glueful/social-login-extension/
└── community/custom-auth-extension/
```

### Option 2: Local Development Extensions
```bash
# Create local extension
./glueful make:extension MyCompanyExtension --local

# Develop in place
extensions/MyCompanyExtension/
```

## Phase 1: Enhanced Extension Manager

### Step 1.1: Update Framework Extension Manager

**File**: `glueful-framework/src/Extensions/ExtensionManager.php`

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Composer\InstalledVersions;
use Glueful\Extensions\Contracts\ExtensionInterface;

class ExtensionManager
{
    private array $loadedExtensions = [];
    private array $extensionPaths = [];
    
    public function __construct(
        private string $basePath,
        private string $localExtensionsPath
    ) {
        $this->extensionPaths = [
            'local' => $this->localExtensionsPath,
            'composer' => $this->basePath . '/vendor'
        ];
    }
    
    /**
     * Load all extensions from both sources
     */
    public function loadExtensions(): void
    {
        // Load Composer package extensions first
        $this->loadComposerExtensions();
        
        // Load local extensions (can override Composer extensions)
        $this->loadLocalExtensions();
        
        // Boot all loaded extensions
        $this->bootExtensions();
    }
    
    /**
     * Load extensions from Composer packages
     */
    private function loadComposerExtensions(): void
    {
        if (!class_exists(InstalledVersions::class)) {
            return; // Composer not available
        }
        
        $packages = InstalledVersions::getInstalledPackages();
        
        foreach ($packages as $packageName) {
            $packageType = InstalledVersions::getInstallPath($packageName);
            
            if ($this->isGluefulExtension($packageName)) {
                $this->loadComposerExtension($packageName);
            }
        }
    }
    
    /**
     * Check if package is a Glueful extension
     */
    private function isGluefulExtension(string $packageName): bool
    {
        $composerJson = InstalledVersions::getRootPackage()['install_path'] . 
                       "/vendor/{$packageName}/composer.json";
        
        if (!file_exists($composerJson)) {
            return false;
        }
        
        $packageData = json_decode(file_get_contents($composerJson), true);
        
        return ($packageData['type'] ?? '') === 'glueful-extension';
    }
    
    /**
     * Load single Composer extension
     */
    private function loadComposerExtension(string $packageName): void
    {
        $packagePath = InstalledVersions::getInstallPath($packageName);
        $composerJson = $packagePath . '/composer.json';
        
        if (!file_exists($composerJson)) {
            return;
        }
        
        $packageData = json_decode(file_get_contents($composerJson), true);
        $extensionConfig = $packageData['extra']['glueful'] ?? [];
        
        if (!isset($extensionConfig['extension-class'])) {
            return;
        }
        
        $extensionClass = $extensionConfig['extension-class'];
        
        if (!class_exists($extensionClass)) {
            return;
        }
        
        $extension = new $extensionClass();
        
        if (!$extension instanceof ExtensionInterface) {
            return;
        }
        
        $this->loadedExtensions[$packageName] = [
            'instance' => $extension,
            'path' => $packagePath,
            'source' => 'composer',
            'config' => $extensionConfig
        ];
    }
    
    /**
     * Load extensions from local directory (existing functionality)
     */
    private function loadLocalExtensions(): void
    {
        if (!is_dir($this->localExtensionsPath)) {
            return;
        }
        
        $directories = glob($this->localExtensionsPath . '/*', GLOB_ONLYDIR);
        
        foreach ($directories as $extensionDir) {
            $this->loadLocalExtension($extensionDir);
        }
    }
    
    /**
     * Load single local extension (existing functionality enhanced)
     */
    private function loadLocalExtension(string $extensionDir): void
    {
        $extensionName = basename($extensionDir);
        $manifestFile = $extensionDir . '/manifest.json';
        
        if (!file_exists($manifestFile)) {
            return;
        }
        
        $manifest = json_decode(file_get_contents($manifestFile), true);
        $extensionClass = $manifest['main_class'] ?? null;
        
        if (!$extensionClass) {
            return;
        }
        
        $extensionFile = $extensionDir . '/' . $extensionName . '.php';
        
        if (!file_exists($extensionFile)) {
            return;
        }
        
        require_once $extensionFile;
        
        if (!class_exists($extensionClass)) {
            return;
        }
        
        $extension = new $extensionClass();
        
        if (!$extension instanceof ExtensionInterface) {
            return;
        }
        
        $this->loadedExtensions[$extensionName] = [
            'instance' => $extension,
            'path' => $extensionDir,
            'source' => 'local',
            'manifest' => $manifest
        ];
    }
    
    /**
     * Get extension information
     */
    public function getExtensionInfo(string $name): ?array
    {
        return $this->loadedExtensions[$name] ?? null;
    }
    
    /**
     * List all loaded extensions
     */
    public function getLoadedExtensions(): array
    {
        return array_keys($this->loadedExtensions);
    }
    
    /**
     * Check if extension is loaded
     */
    public function isExtensionLoaded(string $name): bool
    {
        return isset($this->loadedExtensions[$name]);
    }
}
```

### Step 1.2: Extension Package Contract

**File**: `glueful-framework/src/Extensions/Contracts/ComposerExtensionInterface.php`

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Contracts;

interface ComposerExtensionInterface extends ExtensionInterface
{
    /**
     * Get extension package name
     */
    public function getPackageName(): string;
    
    /**
     * Get extension version
     */
    public function getVersion(): string;
    
    /**
     * Get extension dependencies
     */
    public function getDependencies(): array;
}
```

## Phase 2: Convert Existing Extensions to Packages

### Step 2.1: Create Extension Package Template

**Repository**: `glueful/extension-template`

```bash
# Create template repository
mkdir glueful-extension-template
cd glueful-extension-template
```

**Template Structure**:
```
glueful-extension-template/
├── src/
│   ├── ExampleExtension.php
│   ├── Controllers/.gitkeep
│   ├── Services/.gitkeep
│   └── config.php
├── tests/
│   └── ExampleTest.php
├── .github/
│   └── workflows/
│       └── test.yml
├── composer.json
├── README.md
├── CHANGELOG.md
└── LICENSE
```

**Template composer.json**:
```json
{
    "name": "vendor/extension-name",
    "description": "Description of your Glueful extension",
    "type": "glueful-extension",
    "keywords": ["glueful", "extension"],
    "homepage": "https://github.com/vendor/extension-name",
    "license": "MIT",
    "authors": [
        {
            "name": "Your Name",
            "email": "your.email@example.com"
        }
    ],
    "require": {
        "php": "^8.2",
        "glueful/framework": "^1.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0"
    },
    "autoload": {
        "psr-4": {
            "Vendor\\ExtensionName\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Vendor\\ExtensionName\\Tests\\": "tests/"
        }
    },
    "extra": {
        "glueful": {
            "extension-class": "Vendor\\ExtensionName\\ExtensionNameExtension",
            "config-file": "src/config.php"
        }
    }
}
```

### Step 2.2: Convert RBAC Extension to Package

**Repository**: `glueful/rbac-extension`

```bash
# Create new repository
mkdir glueful-rbac-extension
cd glueful-rbac-extension

# Copy from existing extension
cp -r ../glueful/extensions/RBAC/* .

# Update composer.json
```

**Updated composer.json**:
```json
{
    "name": "glueful/rbac-extension",
    "description": "Role-Based Access Control extension for Glueful API Framework",
    "type": "glueful-extension",
    "keywords": ["glueful", "rbac", "permissions", "roles", "authorization"],
    "homepage": "https://github.com/glueful/rbac-extension",
    "license": "MIT",
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
            "config-file": "src/config.php",
            "routes-file": "src/routes.php"
        }
    }
}
```

### Step 2.3: Convert Other Core Extensions

**Priority Order**:
1. ✅ `glueful/rbac-extension` - Most commonly used
2. ✅ `glueful/admin-extension` - Admin interface
3. ✅ `glueful/social-login-extension` - OAuth authentication  
4. ✅ `glueful/email-notification-extension` - Email system
5. ✅ `glueful/beanstalkd-queue-extension` - Queue driver

## Phase 3: CLI Tools for Extension Management

### Step 3.1: Extension Discovery Command

**File**: `glueful-framework/src/Commands/ExtensionDiscoverCommand.php`

```php
<?php

declare(strict_types=1);

namespace Glueful\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

class ExtensionDiscoverCommand extends Command
{
    protected static $defaultName = 'extension:discover';
    protected static $defaultDescription = 'Discover available extensions';
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Discovering Glueful Extensions...</info>');
        
        // Get popular extensions from registry
        $extensions = $this->getAvailableExtensions();
        
        $table = new Table($output);
        $table->setHeaders(['Name', 'Description', 'Downloads', 'Version']);
        
        foreach ($extensions as $extension) {
            $table->addRow([
                $extension['name'],
                $extension['description'],
                number_format($extension['downloads']),
                $extension['version']
            ]);
        }
        
        $table->render();
        
        $output->writeln('');
        $output->writeln('<comment>Install extensions with:</comment> composer require <package-name>');
        
        return Command::SUCCESS;
    }
    
    private function getAvailableExtensions(): array
    {
        // This would fetch from extensions.glueful.com API
        return [
            [
                'name' => 'glueful/rbac-extension',
                'description' => 'Role-Based Access Control',
                'downloads' => 1250,
                'version' => '2.1.0'
            ],
            [
                'name' => 'glueful/admin-extension', 
                'description' => 'Admin Interface',
                'downloads' => 890,
                'version' => '1.5.2'
            ],
            // More extensions...
        ];
    }
}
```

### Step 3.2: Extension Installation Helper

**File**: `glueful-framework/src/Commands/ExtensionInstallCommand.php`

```php
<?php

declare(strict_types=1);

namespace Glueful\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class ExtensionInstallCommand extends Command
{
    protected static $defaultName = 'extension:install';
    protected static $defaultDescription = 'Install a Glueful extension package';
    
    protected function configure(): void
    {
        $this->addArgument('package', InputArgument::REQUIRED, 'Package name to install');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $package = $input->getArgument('package');
        
        $output->writeln("<info>Installing extension: {$package}</info>");
        
        // Run composer require
        $process = new Process(['composer', 'require', $package]);
        $process->run();
        
        if ($process->isSuccessful()) {
            $output->writeln("<info>✅ Extension {$package} installed successfully!</info>");
            
            // Run any post-install tasks
            $this->runPostInstallTasks($package, $output);
            
            return Command::SUCCESS;
        } else {
            $output->writeln("<error>❌ Failed to install {$package}</error>");
            $output->writeln($process->getErrorOutput());
            return Command::FAILURE;
        }
    }
    
    private function runPostInstallTasks(string $package, OutputInterface $output): void
    {
        $output->writeln('<comment>Running post-install tasks...</comment>');
        
        // Run migrations if they exist
        // Copy assets if needed
        // Update configurations
        
        $output->writeln('<info>Post-install tasks completed</info>');
    }
}
```

### Step 3.3: Local Extension Generator

**File**: `glueful-framework/src/Commands/MakeExtensionCommand.php`

```php
<?php

declare(strict_types=1);

namespace Glueful\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeExtensionCommand extends Command
{
    protected static $defaultName = 'make:extension';
    protected static $defaultDescription = 'Create a new extension';
    
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Extension name')
             ->addOption('local', null, InputOption::VALUE_NONE, 'Create local extension')
             ->addOption('package', null, InputOption::VALUE_NONE, 'Create package-ready extension');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $isLocal = $input->getOption('local');
        $isPackage = $input->getOption('package');
        
        if ($isLocal) {
            return $this->createLocalExtension($name, $output);
        }
        
        if ($isPackage) {
            return $this->createPackageExtension($name, $output);
        }
        
        // Default to local extension
        return $this->createLocalExtension($name, $output);
    }
    
    private function createLocalExtension(string $name, OutputInterface $output): int
    {
        $path = "extensions/{$name}";
        
        if (is_dir($path)) {
            $output->writeln("<error>Extension {$name} already exists</error>");
            return Command::FAILURE;
        }
        
        // Create directory structure
        mkdir($path, 0755, true);
        mkdir("{$path}/src", 0755, true);
        mkdir("{$path}/assets", 0755, true);
        
        // Generate files from stubs
        $this->generateExtensionFiles($name, $path);
        
        $output->writeln("<info>✅ Local extension {$name} created in {$path}</info>");
        
        return Command::SUCCESS;
    }
    
    private function createPackageExtension(string $name, OutputInterface $output): int
    {
        // Create package-ready extension outside the project
        $path = "../{$name}-extension";
        
        // Create from template
        $this->createFromTemplate($name, $path);
        
        $output->writeln("<info>✅ Package extension {$name} created in {$path}</info>");
        $output->writeln("<comment>Next steps:</comment>");
        $output->writeln("1. cd {$path}");
        $output->writeln("2. git init && git add .");
        $output->writeln("3. Create GitHub repository");
        $output->writeln("4. Submit to Packagist");
        
        return Command::SUCCESS;
    }
}
```

## Phase 4: Extension Registry & Ecosystem

### Step 4.1: Extension Registry Website

**Domain**: `extensions.glueful.com`

**Features**:
- Browse and search extensions
- Installation instructions
- Ratings and reviews  
- Documentation links
- Download statistics
- Compatibility information

### Step 4.2: Extension Submission Process

**Automated Validation**:
1. Check composer.json format
2. Validate extension class exists
3. Run basic tests
4. Security scan
5. Documentation check

### Step 4.3: Featured Extensions

**Core Extensions** (Official):
- `glueful/rbac-extension`
- `glueful/admin-extension`
- `glueful/social-login-extension`
- `glueful/email-notification-extension`

**Community Extensions**:
- `vendor/custom-auth-extension`
- `vendor/payment-gateway-extension`
- `vendor/elasticsearch-extension`

## Phase 5: Migration Path & Compatibility

### Step 5.1: Backward Compatibility

**Extensions continue working locally** during transition:
- Existing `extensions/` directory still loads
- No breaking changes to extension API
- Gradual migration to packages

### Step 5.2: Migration Helper

**Command**: `./glueful extension:migrate-to-package ExtensionName`

**Process**:
1. Create package structure from local extension
2. Update composer.json 
3. Create GitHub repository (optional)
4. Generate submission checklist

### Step 5.3: Hybrid Documentation

**Update docs to show both approaches**:

```markdown
## Installing Extensions

### Option 1: Composer Package
composer require glueful/rbac-extension

### Option 2: Local Development  
./glueful make:extension MyExtension --local
```

## Implementation Timeline

### Week 1: Enhanced Extension Manager
- ✅ Update framework extension manager
- ✅ Support Composer package loading
- ✅ Test with existing local extensions

### Week 2: Convert Core Extensions  
- ✅ Create extension template repository
- ✅ Convert RBAC extension to package
- ✅ Convert Admin extension to package
- ✅ Publish to Packagist

### Week 3: CLI Tools
- ✅ Extension discovery command
- ✅ Extension installation helper
- ✅ Local extension generator
- ✅ Migration tools

### Week 4: Registry & Documentation
- ✅ Create extensions registry website
- ✅ Update documentation
- ✅ Community guidelines
- ✅ Submission process

## Success Criteria

### ✅ Technical Success
- [ ] Extensions load from both Composer and local sources
- [ ] All existing extensions work without modification
- [ ] Package extensions install via `composer require`
- [ ] Local extensions create via `./glueful make:extension`
- [ ] CLI tools work correctly

### ✅ Ecosystem Success  
- [ ] Core extensions published as packages
- [ ] Extension registry is live
- [ ] Community starts creating package extensions
- [ ] Documentation is comprehensive
- [ ] Migration path is smooth

### ✅ Developer Experience
- [ ] Installing extensions is easy (`composer require`)
- [ ] Creating extensions is simple (`./glueful make:extension`)
- [ ] Extension discovery works well
- [ ] Package management is reliable
- [ ] Local development remains flexible

## Future Enhancements

### Phase 6: Advanced Features (Future)
- Extension marketplace with paid extensions
- Extension testing in isolated environments  
- Automatic dependency management
- Extension performance monitoring
- A/B testing framework for extensions

## Conclusion

This migration plan builds upon the package architecture foundation to create a world-class extension ecosystem for Glueful. By supporting both Composer packages and local development, we maximize developer flexibility while enabling community growth.

The hybrid approach ensures that:
- **Existing users** aren't disrupted
- **New users** get modern package management
- **Extension developers** can choose their distribution method
- **The ecosystem** can grow organically

This positions Glueful to have one of the best extension systems in the PHP ecosystem.