<?php

declare(strict_types=1);

namespace Glueful\DI;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Config\FileLocator;
use Glueful\DI\Passes\TaggedServicePass;
use Glueful\Extensions\ProviderLocator;
use Glueful\Extensions\ExtensionServiceCompiler;

/**
 * Pure Symfony Container Factory - No legacy code
 */
class ContainerFactory
{
    public static function create(bool $isProduction = false): Container
    {
        if ($isProduction && self::hasCompiledContainer()) {
            return self::loadCompiledContainer();
        }

        return self::buildDevelopmentContainer();
    }

    private static function buildDevelopmentContainer(): Container
    {
        $builder = new ContainerBuilder();
        self::configureContainer($builder);
        $builder->compile();

        return new Container($builder);
    }

    public static function configureContainer(ContainerBuilder $builder): void
    {
        self::setupParameters($builder);
        self::addCompilerPasses($builder);
        self::loadServiceProviders($builder);
        self::loadConfigurationFiles($builder);
        self::loadExtensionServices($builder);
    }

    private static function setupParameters(ContainerBuilder $builder): void
    {
        // Add framework parameters
        $builder->setParameter('glueful.env', config('app.env', env('APP_ENV', 'production')));
        $builder->setParameter('glueful.debug', (bool) config('app.debug', env('APP_DEBUG', false)));
        $builder->setParameter('glueful.version', '1.0.0');
        $builder->setParameter('glueful.root_dir', base_path());
        $builder->setParameter('glueful.config_dir', 'config');
        $builder->setParameter('glueful.storage_dir', 'storage');
        $builder->setParameter('glueful.cache_dir', 'storage/cache');
        $builder->setParameter('glueful.log_dir', 'storage/logs');

        // Add validation configuration parameter
        $builder->setParameter('validation.config', [
            'cache_enabled' => true,
            'cache_ttl' => 3600,
            'lazy_loading' => true,
            'preload_common' => true,
            'debug' => (bool) config('app.debug', env('APP_DEBUG', false)),
        ]);

        // Add filesystem configuration parameters
        $builder->setParameter('filesystem.file_manager', [
            'root_path' => base_path(),
            'temp_path' => 'storage/tmp',
            'max_file_size' => 10 * 1024 * 1024, // 10MB
            'allowed_extensions' => ['php', 'json', 'txt', 'md', 'yml', 'yaml'],
            'forbidden_paths' => ['.git', 'vendor', 'node_modules'],
        ]);

        $builder->setParameter('filesystem.file_finder', [
            'search_paths' => ['api', 'config', 'extensions'],
            'excluded_dirs' => ['vendor', 'node_modules', '.git', 'storage/cache'],
            'max_depth' => 10,
            'case_sensitive' => true,
        ]);

        // Add archive configuration parameter
        $builder->setParameter('archive.config', [
            'enabled' => true,
            'retention_days' => 365,
            'compression' => 'gzip',
            'archive_table' => 'archived_data',
            'batch_size' => 1000,
            'max_archive_size' => 100 * 1024 * 1024, // 100MB
            'storage_path' => 'storage/archives',
            'auto_cleanup' => true,
        ]);
    }

    private static function addCompilerPasses(ContainerBuilder $builder): void
    {
        $builder->addCompilerPass(new TaggedServicePass());
    }

    private static function loadServiceProviders(ContainerBuilder $builder): void
    {
        /** @var array<ServiceProviderInterface> $providers */
        $providers = self::getServiceProviders();

        foreach ($providers as $provider) {
            $provider->register($builder);

            // Add compiler passes from provider
            foreach ($provider->getCompilerPasses() as $pass) {
                $builder->addCompilerPass($pass);
            }
        }
    }

    /**
     * @return array<ServiceProviderInterface>
     */
    private static function getServiceProviders(): array
    {
        /** @var array<ServiceProviderInterface> $providers */
        $providers = [];

        // Get service provider instances from their registration files
        $providerClasses = [
            \Glueful\DI\ServiceProviders\CoreServiceProvider::class,
            \Glueful\DI\ServiceProviders\ConfigServiceProvider::class,
            \Glueful\DI\ServiceProviders\AutoConfigServiceProvider::class,
            \Glueful\DI\ServiceProviders\HttpPsr15ServiceProvider::class,

            \Glueful\DI\ServiceProviders\VarDumperServiceProvider::class,
            \Glueful\DI\ServiceProviders\ConsoleServiceProvider::class,
            \Glueful\DI\ServiceProviders\ValidatorServiceProvider::class,
            \Glueful\DI\ServiceProviders\HttpClientServiceProvider::class,
            \Glueful\DI\ServiceProviders\SerializerServiceProvider::class,
            \Glueful\DI\ServiceProviders\FileServiceProvider::class,
            \Glueful\DI\ServiceProviders\RequestServiceProvider::class,
            \Glueful\DI\ServiceProviders\SecurityServiceProvider::class,
            \Glueful\DI\ServiceProviders\ControllerServiceProvider::class,
            \Glueful\DI\ServiceProviders\RepositoryServiceProvider::class,
            \Glueful\DI\ServiceProviders\ExtensionServiceProvider::class,
            \Glueful\DI\ServiceProviders\EventServiceProvider::class,
            \Glueful\DI\ServiceProviders\QueueServiceProvider::class,
            \Glueful\DI\ServiceProviders\LockServiceProvider::class,
            \Glueful\DI\ServiceProviders\ArchiveServiceProvider::class,
            \Glueful\DI\ServiceProviders\SpaServiceProvider::class,
        ];

        foreach ($providerClasses as $providerClass) {
            if (class_exists($providerClass)) {
                $providers[] = new $providerClass();
            }
        }

        return $providers;
    }

    private static function loadConfigurationFiles(ContainerBuilder $builder): void
    {
        // Service definitions will be handled by service providers in Phase 4
        // This method is reserved for optional YAML service configurations

        // Load YAML services if they exist (optional supplementary configuration)
        if (file_exists('config/services.yaml')) {
            $yamlLoader = new YamlFileLoader($builder, new FileLocator('config/'));
            $yamlLoader->load('services.yaml');
        }
    }

    private static function loadExtensionServices(ContainerBuilder $builder): void
    {
        // Compile extension services discovered from providers into the builder
        // Discovery is unified via ProviderLocator to keep dev/prod parity
        $compiler = new ExtensionServiceCompiler($builder);
        foreach (ProviderLocator::all() as $providerClass) {
            if (method_exists($providerClass, 'services')) {
                try {
                    /** @var array<string, array<string,mixed>> $defs */
                    $defs = $providerClass::services();
                    $compiler->register($defs, $providerClass);
                } catch (\Throwable $e) {
                    // Best-effort: don't break container build if an extension misbehaves
                    error_log("[Extensions] Failed compiling services for {$providerClass}: " . $e->getMessage());
                }
            }
        }
    }

    public static function hasCompiledContainer(): bool
    {
        $file = self::getCompiledContainerPath();
        if (!file_exists($file)) {
            return false;
        }
        // Ensure class is available
        require_once $file;
        return class_exists(self::getCompiledContainerClass());
    }

    private static function loadCompiledContainer(): Container
    {
        $file = self::getCompiledContainerPath();
        if (!file_exists($file)) {
            return self::buildDevelopmentContainer();
        }

        require_once $file;
        $class = self::getCompiledContainerClass();
        if (!class_exists($class)) {
            return self::buildDevelopmentContainer();
        }

        /** @var \Symfony\Component\DependencyInjection\ContainerInterface $compiled */
        $compiled = new $class();
        return new Container($compiled);
    }

    private static function getCompiledContainerPath(): string
    {
        $env = (string) config('app.env', env('APP_ENV', 'production'));
        $hash = self::computeConfigHash();
        $dir = base_path('storage/container');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . "/container_{$env}_{$hash}.php";
    }

    private static function getCompiledContainerClass(): string
    {
        $env = (string) config('app.env', env('APP_ENV', 'production'));
        $hash = self::computeConfigHash();
        return 'Glueful\\\\DI\\\\Compiled\\\\Container_' . $env . '_' . $hash;
    }

    private static function computeConfigHash(): string
    {
        $configDir = config_path();
        $parts = [];
        if (is_dir($configDir)) {
            foreach (glob($configDir . '/*.php') as $file) {
                $hash = md5_file($file);
                $parts[] = $hash !== false ? $hash : '';
            }
        }
        return substr(sha1(implode('|', $parts)), 0, 8);
    }

    /**
     * Build, compile, and dump a production container for current config state
     */
    public static function buildProductionContainer(): Container
    {
        $builder = new ContainerBuilder();
        self::configureContainer($builder);
        $builder->compile();

        $file = self::getCompiledContainerPath();
        $class = self::getCompiledContainerClass();
        $ns = 'Glueful\\\\DI\\\\Compiled';

        try {
            $dumper = new PhpDumper($builder);
            $code = $dumper->dump([
                'class' => basename(str_replace('\\\\', '/', $class)),
                'namespace' => trim($ns, '\\'),
                'base_class' => 'Symfony\\\\Component\\\\DependencyInjection\\\\Container',
            ]);
            file_put_contents($file, $code);
            // Cleanup older compiled containers for this environment
            $dir = dirname($file);
            $env = (string) config('app.env', env('APP_ENV', 'production'));
            $files = glob($dir . "/container_{$env}_*.php");
            foreach ($files !== false ? $files : [] as $old) {
                if ($old !== $file) {
                    @unlink($old);
                }
            }
        } catch (\Throwable) {
            // On failure, fall back to development container
            return new Container($builder);
        }

        require_once $file;
        /** @var \Symfony\Component\DependencyInjection\ContainerInterface $compiled */
        $compiled = new (str_replace('\\\\', '\\', $class))();
        return new Container($compiled);
    }
}
