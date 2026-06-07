<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Container\Definition\AliasDefinition;
use Glueful\Container\Definition\DefinitionInterface;

final class CoreProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // Logger service (Monolog) with StandardLogProcessor parity
        $defs['logger'] = new FactoryDefinition('logger', function (): \Psr\Log\LoggerInterface {
            // Framework logging can be toggled via config
            $config = function_exists('config') ? (array) config($this->context, 'logging.framework', []) : [];
            $enabled = $config['enabled'] ?? true;
            if ($enabled !== true) {
                return new \Psr\Log\NullLogger();
            }

            if ((string) (\function_exists('env') ? env('APP_ENV', '') : ($_ENV['APP_ENV'] ?? '')) === 'testing') {
                return new \Psr\Log\NullLogger();
            }

            $channel = is_string($config['channel'] ?? null) ? $config['channel'] : 'framework';
            $logger = new \Monolog\Logger($channel);

            // Level from config/debug
            $levelName = (string) ($config['level'] ?? 'info');
            if (\function_exists('env') && (bool) env('APP_DEBUG', false)) {
                $levelName = 'debug';
            }
            $level = \Monolog\Logger::toMonologLevel($levelName);

            // Write to file path if configured, else stdout
            $channelConfig = function_exists('config')
                ? (array) config($this->context, 'logging.channels.framework', [])
                : [];
            $path = is_string($channelConfig['path'] ?? null)
                ? $channelConfig['path']
                : (function_exists('base_path')
                    ? base_path($this->context, 'storage/logs/framework-' . date('Y-m-d') . '.log')
                    : 'php://stdout');
            $handler = new \Monolog\Handler\StreamHandler($path, $level);
            $logger->pushHandler($handler);

            // Attach StandardLogProcessor with env + version and robust user resolver
            try {
                $env = (string) (
                    (function_exists('config') ? config($this->context, 'app.env', null) : null)
                    ?? ($_ENV['APP_ENV'] ?? 'production')
                );
                $version = '1.0.0';
                if (class_exists('Composer\\InstalledVersions')) {
                    try {
                        $maybe = \Composer\InstalledVersions::getPrettyVersion('glueful/framework');
                        if (is_string($maybe) && $maybe !== '') {
                            $version = $maybe;
                        } else {
                            $version = (string) (
                                (function_exists('config') ? config($this->context, 'app.version_full', null) : null)
                                ?? '1.0.0'
                            );
                        }
                    } catch (\Throwable) {
                        $version = (string) (
                            (function_exists('config') ? config($this->context, 'app.version_full', null) : null)
                            ?? '1.0.0'
                        );
                    }
                }

                $userIdResolver = function (): ?string {
                    try {
                        // Priority: Request attribute user via global container
                        if ($this->context->hasContainer()) {
                            $c = $this->context->getContainer();
                            if ($c->has('request')) {
                                $req = $c->get('request');
                                if (is_object($req) && method_exists($req, 'get')) {
                                    // Symfony Request: attributes bag
                                    if (property_exists($req, 'attributes') && $req->attributes->has('user')) {
                                        $user = $req->attributes->get('user');
                                        if (is_object($user)) {
                                            foreach (['getId','id','getUuid','uuid'] as $m) {
                                                if (method_exists($user, $m)) {
                                                    /** @phpstan-ignore-next-line */
                                                    $id = $user->{$m}();
                                                    if (is_scalar($id)) {
                                                        return (string) $id;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }

                        // AuthenticationService current user if available
                        $authServiceClass = \Glueful\Auth\AuthenticationService::class;
                        if (function_exists('has_service') && has_service($this->context, $authServiceClass)) {
                            $auth = app($this->context, $authServiceClass);
                            if (method_exists($auth, 'getCurrentUser')) {
                                $u = $auth->getCurrentUser();
                                if ($u && method_exists($u, 'getId')) {
                                    $id = $u->getId();
                                    return is_scalar($id) ? (string) $id : null;
                                }
                            }
                        }

                        // JWT header if present
                        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                            $authHeader = (string) $_SERVER['HTTP_AUTHORIZATION'];
                            if (str_starts_with($authHeader, 'Bearer ')) {
                                $token = substr($authHeader, 7);
                                $tokenManagerClass = \Glueful\Auth\TokenManager::class;
                                if (function_exists('has_service') && has_service($this->context, $tokenManagerClass)) {
                                    $tm = app($this->context, $tokenManagerClass);
                                    if (method_exists($tm, 'validateToken')) {
                                        $payload = $tm->validateToken($token);
                                        if (is_array($payload)) {
                                            if (isset($payload['sub'])) {
                                                return (string) $payload['sub'];
                                            }
                                            if (isset($payload['user_id'])) {
                                                return (string) $payload['user_id'];
                                            }
                                        }
                                    }
                                }
                            }
                        }

                        // Session fallback
                        if (isset($_SESSION['user_uuid']) && is_string($_SESSION['user_uuid'])) {
                            return $_SESSION['user_uuid'];
                        }
                        if (isset($_SESSION['user_id']) && is_scalar($_SESSION['user_id'])) {
                            return (string) $_SESSION['user_id'];
                        }

                        // Laravel-style helper if present
                        if (function_exists('auth')) {
                            /** @var callable():object|null $f */
                            $f = 'auth';
                            $guard = $f();
                            if (
                                is_object($guard) &&
                                method_exists($guard, 'check') &&
                                method_exists($guard, 'id') &&
                                $guard->check()
                            ) {
                                $id = $guard->id();
                                return is_scalar($id) ? (string) $id : null;
                            }
                        }
                    } catch (\Throwable) {
                        // ignore resolver errors
                    }
                    return null;
                };

                $logger->pushProcessor(new \Glueful\Logging\StandardLogProcessor($env, $version, $userIdResolver));
            } catch (\Throwable) {
                // processor is optional
            }

            return $logger;
        });

        // Alias PSR LoggerInterface to the same logger instance
        $defs[\Psr\Log\LoggerInterface::class] = new AliasDefinition(\Psr\Log\LoggerInterface::class, 'logger');

        // Cache store via existing CacheFactory (transitional factory)
        $defs['cache.store'] = new FactoryDefinition(
            'cache.store',
            /** @return \Glueful\Cache\CacheStore */
            function () {
                return \Glueful\Cache\CacheFactory::create();
            }
        );

        // Tag cache store so consumers can get a tagged iterator if needed
        $this->tag('cache.store', 'cache.pool', 0);
        // Initial lazy warmup tags for heavy services
        $this->tag('cache.store', 'lazy.background', 0);

        // Alias for CacheStore class name resolution
        $defs[\Glueful\Cache\CacheStore::class] = new AliasDefinition(\Glueful\Cache\CacheStore::class, 'cache.store');

        // Database services (neutral factory for connection)
        $defs['database'] = new FactoryDefinition(
            'database',
            /** @return \Glueful\Database\Connection|\Glueful\Database\PooledConnection */
            function () {
                if (class_exists('\Glueful\Database\Connection')) {
                    $config = (array) (function_exists('config') ? config($this->context, 'database', []) : []);
                    return new \Glueful\Database\Connection($config, $this->context);
                }
                throw new \RuntimeException('Database connection factory not configured');
            }
        );
        // Tag database for background warmup
        $this->tag('database', 'lazy.background', 0);

        // QueryBuilder and SchemaBuilder via database
        $defs[\Glueful\Database\QueryBuilder::class] = new FactoryDefinition(
            \Glueful\Database\QueryBuilder::class,
            fn(\Psr\Container\ContainerInterface $c) => $c->get('database')->createQueryBuilder()
        );
        $defs[\Glueful\Database\Schema\Interfaces\SchemaBuilderInterface::class] = new FactoryDefinition(
            \Glueful\Database\Schema\Interfaces\SchemaBuilderInterface::class,
            fn(\Psr\Container\ContainerInterface $c) => $c->get('database')->getSchemaBuilder()
        );

        // Security utilities
        $defs[\Glueful\Security\RandomStringGenerator::class] =
            $this->autowire(\Glueful\Security\RandomStringGenerator::class);

        // Auth core services
        $defs[\Glueful\Auth\AuthBootstrap::class] = new FactoryDefinition(
            \Glueful\Auth\AuthBootstrap::class,
            fn() => new \Glueful\Auth\AuthBootstrap($this->context)
        );

        $defs[\Glueful\Auth\AuthenticationManager::class] = new FactoryDefinition(
            \Glueful\Auth\AuthenticationManager::class,
            fn(\Psr\Container\ContainerInterface $c) => $c->get(\Glueful\Auth\AuthBootstrap::class)->getManager()
        );

        $defs[\Glueful\Auth\TokenManager::class] = new FactoryDefinition(
            \Glueful\Auth\TokenManager::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Auth\TokenManager(
                $this->context,
                null,
                $c->get(\Glueful\Auth\AuthenticationManager::class)
            )
        );

        $defs[\Glueful\Auth\AuthenticationGuard::class] = new FactoryDefinition(
            \Glueful\Auth\AuthenticationGuard::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Auth\AuthenticationGuard(
                $c->get(\Glueful\Auth\AuthenticationService::class)
            )
        );

        // Core email-PIN 2FA services. Inert unless auth.two_factor.enabled is true.
        $defs[\Glueful\Auth\TwoFactor\JtiBlocklist::class] = new FactoryDefinition(
            \Glueful\Auth\TwoFactor\JtiBlocklist::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Auth\TwoFactor\JtiBlocklist(
                $c->get(\Glueful\Cache\CacheStore::class)
            )
        );

        $defs[\Glueful\Auth\TwoFactor\ChallengeTokenIssuer::class] = new FactoryDefinition(
            \Glueful\Auth\TwoFactor\ChallengeTokenIssuer::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Auth\TwoFactor\ChallengeTokenIssuer(
                $c->get(\Glueful\Auth\TwoFactor\JtiBlocklist::class),
                (int) config($this->context, 'auth.two_factor.challenge_ttl', 300)
            )
        );

        $defs[\Glueful\Auth\LoginResponseShaper::class] = new FactoryDefinition(
            \Glueful\Auth\LoginResponseShaper::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Auth\LoginResponseShaper(
                $this->context
            )
        );

        // TwoFactorService moved to glueful/users (owns users.two_factor_enabled state); it is
        // registered by UsersServiceProvider. ChallengeTokenIssuer + JtiBlocklist (above) stay in
        // core as pure token mechanics that the moved service consumes across the boundary.

        // HTTP request — delegate to RequestProvider's shared definition
        $defs['request'] = new FactoryDefinition(
            'request',
            fn(\Psr\Container\ContainerInterface $c) => $c->get(
                \Symfony\Component\HttpFoundation\Request::class
            )
        );

        // Permission services

        // Declarative permission catalog (shared singleton; filled by ExtensionManager::aggregatePermissionCatalog()).
        $defs[\Glueful\Permissions\Catalog\PermissionRegistry::class] = new FactoryDefinition(
            \Glueful\Permissions\Catalog\PermissionRegistry::class,
            fn(): \Glueful\Permissions\Catalog\PermissionRegistry
                => new \Glueful\Permissions\Catalog\PermissionRegistry()
        );

        // Route attribute scanner for permissions:diff (enforced-permission discovery).
        $defs[\Glueful\Permissions\Catalog\PermissionAttributeScanner::class] = new FactoryDefinition(
            \Glueful\Permissions\Catalog\PermissionAttributeScanner::class,
            fn(\Psr\Container\ContainerInterface $c): \Glueful\Permissions\Catalog\PermissionAttributeScanner
                => new \Glueful\Permissions\Catalog\PermissionAttributeScanner($c->get(\Glueful\Routing\Router::class))
        );

        // Gate service with voters
        $defs[\Glueful\Permissions\Gate::class] = new FactoryDefinition(
            \Glueful\Permissions\Gate::class,
            function (\Psr\Container\ContainerInterface $c): \Glueful\Permissions\Gate {
                $config = function_exists('config') ? (array) config($this->context, 'permissions', []) : [];

                $gate = new \Glueful\Permissions\Gate(
                    $config['strategy'] ?? 'affirmative',
                    (bool) ($config['allow_deny_override'] ?? false)
                );

                // 1. Register super role voter if configured
                if (isset($config['super_roles']) && count($config['super_roles']) > 0) {
                    $gate->registerVoter(new \Glueful\Permissions\Voters\SuperRoleVoter($config['super_roles']));
                }

                // 2. Register policy voter to connect PolicyRegistry
                if ($c->has(\Glueful\Permissions\PolicyRegistry::class)) {
                    $gate->registerVoter(new \Glueful\Permissions\Voters\PolicyVoter(
                        $c->get(\Glueful\Permissions\PolicyRegistry::class)
                    ));
                }

                // 3. Register role voter with configured roles
                $gate->registerVoter(new \Glueful\Permissions\Voters\RoleVoter($config['roles'] ?? []));

                // 3b. Registry-backed role voter: lets DECLARED roles enforce (fallback path).
                if ($c->has(\Glueful\Permissions\Catalog\PermissionRegistry::class)) {
                    $gate->registerVoter(new \Glueful\Permissions\Voters\RegistryRoleVoter(
                        $c->get(\Glueful\Permissions\Catalog\PermissionRegistry::class)
                    ));
                }

                // 4. Register scope voter
                $gate->registerVoter(new \Glueful\Permissions\Voters\ScopeVoter());

                // 5. Register ownership voter
                $gate->registerVoter(new \Glueful\Permissions\Voters\OwnershipVoter());

                return $gate;
            }
        );

        // Fail-closed default: no user store in core. Any app or user-store extension overrides
        // this binding with the real UserProviderInterface implementation (glueful/users is the
        // first-party reference, but any provider — LDAP, external IdP, custom store — can bind it).
        $defs[\Glueful\Auth\Contracts\UserProviderInterface::class] = new FactoryDefinition(
            \Glueful\Auth\Contracts\UserProviderInterface::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Auth\NullUserProvider()
        );

        // IdentityResolver folds every service tagged 'identity.claims_provider' (priority-sorted),
        // same consumption pattern as 'console.commands'. Status gate + additive claims fold.
        $defs[\Glueful\Auth\IdentityResolver::class] = new FactoryDefinition(
            \Glueful\Auth\IdentityResolver::class,
            function (\Psr\Container\ContainerInterface $c): \Glueful\Auth\IdentityResolver {
                $providers = $c->has('identity.claims_provider') ? $c->get('identity.claims_provider') : [];
                $allowed = (array) (function_exists('config')
                    ? config($this->context, 'security.auth.allowed_login_statuses', ['active'])
                    : ['active']);
                return new \Glueful\Auth\IdentityResolver(
                    is_array($providers) ? array_values($providers) : [],
                    array_values($allowed)
                );
            }
        );

        // Policy registry service
        $defs[\Glueful\Permissions\PolicyRegistry::class] = new FactoryDefinition(
            \Glueful\Permissions\PolicyRegistry::class,
            function (\Psr\Container\ContainerInterface $c): \Glueful\Permissions\PolicyRegistry {
                $config = function_exists('config') ? (array) config($this->context, 'permissions', []) : [];
                return new \Glueful\Permissions\PolicyRegistry($config['policies'] ?? []);
            }
        );

        $defs['permission.manager'] = new FactoryDefinition(
            'permission.manager',
            function (\Psr\Container\ContainerInterface $c) {
                $manager = \Glueful\Permissions\PermissionManager::getInstance(
                    $c->get(\Glueful\Auth\SessionCacheManager::class)
                );

                // Inject Gate if available
                if ($c->has(\Glueful\Permissions\Gate::class)) {
                    $manager->setGate($c->get(\Glueful\Permissions\Gate::class));
                }

                // Inject permissions config
                $config = function_exists('config') ? (array) config($this->context, 'permissions', []) : [];
                $manager->setPermissionsConfig($config);

                return $manager;
            }
        );
        $defs[\Glueful\Permissions\PermissionCache::class] =
            $this->autowire(\Glueful\Permissions\PermissionCache::class);

        // RequestUserContext service
        $defs[\Glueful\Http\RequestUserContext::class] = new FactoryDefinition(
            \Glueful\Http\RequestUserContext::class,
            function (\Psr\Container\ContainerInterface $c) {
                $context = \Glueful\Http\RequestUserContext::getInstance();

                // Inject Gate if available
                if ($c->has(\Glueful\Permissions\Gate::class)) {
                    $context->setGate($c->get(\Glueful\Permissions\Gate::class));
                }

                // Inject permissions config
                $config = function_exists('config') ? (array) config($this->context, 'permissions', []) : [];
                $context->setPermissionsConfig($config);

                return $context;
            }
        );

        // Session services
        $defs[\Glueful\Auth\SessionCacheManager::class] = $this->autowire(\Glueful\Auth\SessionCacheManager::class);
        $defs[\Glueful\Auth\SessionAnalytics::class] = $this->autowire(\Glueful\Auth\SessionAnalytics::class);

        // Authentication service (explicit factory to handle optional param)
        $defs[\Glueful\Auth\AuthenticationService::class] = new FactoryDefinition(
            \Glueful\Auth\AuthenticationService::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Auth\AuthenticationService(
                sessionStore: $c->get(\Glueful\Auth\Interfaces\SessionStoreInterface::class),
                sessionCacheManager: $c->get(\Glueful\Auth\SessionCacheManager::class),
                context: $this->context,
                authManager: $c->get(\Glueful\Auth\AuthenticationManager::class),
                tokenManager: $c->get(\Glueful\Auth\TokenManager::class),
                userProvider: $c->get(\Glueful\Auth\Contracts\UserProviderInterface::class),
                identityResolver: $c->get(\Glueful\Auth\IdentityResolver::class)
            )
        );

                // Performance
        $defs[\Glueful\Performance\MemoryManager::class] = $this->autowire(\Glueful\Performance\MemoryManager::class);

        // Core services
        $defs[\Glueful\Services\ApiMetricsService::class] = $this->autowire(\Glueful\Services\ApiMetricsService::class);
        $defs[\Glueful\Services\HealthService::class] = $this->autowire(\Glueful\Services\HealthService::class);
        $defs[\Glueful\Security\SecurityManager::class] = $this->autowire(\Glueful\Security\SecurityManager::class);

        // Cache-related services
        $defs[\Glueful\Cache\CacheWarmupService::class] = $this->autowire(\Glueful\Cache\CacheWarmupService::class);
        $defs[\Glueful\Cache\DistributedCacheService::class] = new FactoryDefinition(
            \Glueful\Cache\DistributedCacheService::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Cache\DistributedCacheService(
                $c->get('cache.store'),
                []
            )
        );
        // Core's response caching runs on the EdgeCacheInterface seam, defaulting to the
        // no-op NullEdgeCache when no CDN integration is installed. A real edge/CDN
        // extension rebinds this interface to its own implementation.
        $defs[\Glueful\Cache\Contracts\EdgeCacheInterface::class] =
            $this->autowire(\Glueful\Cache\NullEdgeCache::class);
        $defs[\Glueful\Database\QueryCacheService::class] = $this->autowire(\Glueful\Database\QueryCacheService::class);

        // Database migrations. Factory (not bare autowire) so core can register its OWN schema —
        // the security spine plus DB-backed platform capabilities — whose owning subsystems all
        // live in core. They ship as first-class, versioned, source-tracked migrations applied
        // through the runner (NOT lazy runtime DDL).
        //
        // findMigrations() RECURSES, so we register only explicit LEAF subdirs of migrations/,
        // never the parent (registering the parent would slurp every capability subdir under the
        // wrong source and bypass the gates). auth/ is always-on (source 'glueful/framework');
        // each capability subdir is registered only when its config gate is on, under its own
        // source 'glueful/framework:<capability>'. All at FOUNDATION priority. Shared, so
        // extensions' loadMigrationsFrom() and the migrate commands see the same instance; tests
        // that construct `new MigrationManager(...)` directly stay isolated (no core paths).
        $defs[\Glueful\Database\Migrations\MigrationManager::class] = new FactoryDefinition(
            \Glueful\Database\Migrations\MigrationManager::class,
            function (): \Glueful\Database\Migrations\MigrationManager {
                $base = \dirname(__DIR__, 3) . '/migrations';
                $foundation = \Glueful\Database\Migrations\MigrationPriority::FOUNDATION;
                $mm = new \Glueful\Database\Migrations\MigrationManager(null, null, $this->context);

                $cfg = fn(string $key, mixed $default): mixed =>
                    \function_exists('config') ? config($this->context, $key, $default) : $default;

                // subdir => enabled. auth is unconditional. locks/queue/uploads derive from their
                // own driver/enable config; the rest are explicit flags in config/capabilities.php.
                $gates = [
                    'auth' => true,
                    'locks' => $cfg('lock.default', 'file') === 'database',
                    'uploads' => (bool) $cfg('uploads.enabled', true),
                    'queue' => $cfg('queue.default', 'sync') === 'database',
                    'scheduler' => (bool) $cfg('capabilities.scheduler', true),
                    'notifications' => (bool) $cfg('capabilities.notifications', true),
                    'metrics' => (bool) $cfg('capabilities.metrics', true),
                ];
                foreach ($gates as $dir => $enabled) {
                    if ($enabled) {
                        // Source is 'glueful/framework' for auth, 'glueful/framework:<cap>' otherwise.
                        $source = $dir === 'auth' ? 'glueful/framework' : 'glueful/framework:' . $dir;
                        // addMigrationPath() no-ops when the dir is absent (safe before a subdir exists).
                        $mm->addMigrationPath($base . '/' . $dir, $foundation, $source);
                    }
                }
                return $mm;
            }
        );

        // Field selection
        $defs[\Glueful\Support\FieldSelection\Projector::class] = new FactoryDefinition(
            \Glueful\Support\FieldSelection\Projector::class,
            function () {
                $cfg = \function_exists('config') ? (array)\config($this->context, 'api.field_selection', []) : [];
                $whitelist = (array)($cfg['whitelists'] ?? []);
                return new \Glueful\Support\FieldSelection\Projector(
                    whitelist: $whitelist,
                    strictDefault: (bool)($cfg['strict'] ?? false),
                    maxDepthDefault: (int)($cfg['maxDepth'] ?? 6),
                    maxFieldsDefault: (int)($cfg['maxFields'] ?? 200),
                    maxItemsDefault: (int)($cfg['maxItems'] ?? 1000),
                );
            }
        );
        $defs[\Glueful\Routing\Middleware\FieldSelectionMiddleware::class] =
            $this->autowire(\Glueful\Routing\Middleware\FieldSelectionMiddleware::class);
        // Alias as string ID for convenience
        $defs['field_selection'] = new FactoryDefinition(
            'field_selection',
            fn(\Psr\Container\ContainerInterface $c) =>
                $c->get(\Glueful\Routing\Middleware\FieldSelectionMiddleware::class)
        );

        // ===== Router and HTTP middleware parity =====

        // Router + supporting services
        $defs[\Glueful\Routing\RouteCache::class] = $this->autowire(\Glueful\Routing\RouteCache::class);
        $defs[\Glueful\Routing\RouteCompiler::class] = $this->autowire(\Glueful\Routing\RouteCompiler::class);
        $defs[\Glueful\Routing\Router::class] = new FactoryDefinition(
            \Glueful\Routing\Router::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Routing\Router($c)
        );
        $defs[\Glueful\Routing\AttributeRouteLoader::class] = new FactoryDefinition(
            \Glueful\Routing\AttributeRouteLoader::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Routing\AttributeRouteLoader(
                $c->get(\Glueful\Routing\Router::class)
            )
        );

        // Tracer default binding (Noop)
        $defs[\Glueful\Observability\Tracing\NoopTracer::class] =
            $this->autowire(\Glueful\Observability\Tracing\NoopTracer::class);
        $defs[\Glueful\Observability\Tracing\TracerInterface::class] =
            new AliasDefinition(
                \Glueful\Observability\Tracing\TracerInterface::class,
                \Glueful\Observability\Tracing\NoopTracer::class
            );

        // Middleware registrations (autowire with sensible defaults)
        $defs[\Glueful\Routing\Middleware\AuthMiddleware::class] =
            $this->autowire(\Glueful\Routing\Middleware\AuthMiddleware::class);
        $defs[\Glueful\Routing\Middleware\CSRFMiddleware::class] =
            $this->autowire(\Glueful\Routing\Middleware\CSRFMiddleware::class);
        $defs[\Glueful\Routing\Middleware\SecurityHeadersMiddleware::class] =
            $this->autowire(\Glueful\Routing\Middleware\SecurityHeadersMiddleware::class);
        $defs[\Glueful\Routing\Middleware\AllowIpMiddleware::class] =
            $this->autowire(\Glueful\Routing\Middleware\AllowIpMiddleware::class);
        $defs[\Glueful\Routing\Middleware\AdminPermissionMiddleware::class] =
            $this->autowire(\Glueful\Routing\Middleware\AdminPermissionMiddleware::class);
        $defs[\Glueful\Routing\Middleware\RequestResponseLoggingMiddleware::class] =
            $this->autowire(\Glueful\Routing\Middleware\RequestResponseLoggingMiddleware::class);
        $defs[\Glueful\Routing\Middleware\TracingMiddleware::class] =
            $this->autowire(\Glueful\Routing\Middleware\TracingMiddleware::class);
        $defs[\Glueful\Routing\Middleware\ConditionalCacheMiddleware::class] =
            $this->autowire(\Glueful\Routing\Middleware\ConditionalCacheMiddleware::class);
        $defs[\Glueful\Routing\Middleware\MetricsMiddleware::class] =
            $this->autowire(\Glueful\Routing\Middleware\MetricsMiddleware::class);
        $defs[\Glueful\Routing\Middleware\LockdownMiddleware::class] =
            $this->autowire(\Glueful\Routing\Middleware\LockdownMiddleware::class);

        // Validation middleware for automatic request validation (#[Validate] and FormRequest)
        $defs[\Glueful\Validation\Middleware\ValidationMiddleware::class] =
            $this->autowire(\Glueful\Validation\Middleware\ValidationMiddleware::class);

        // Gate-based permission middleware
        $defs[\Glueful\Permissions\Middleware\GateAttributeMiddleware::class] = new FactoryDefinition(
            \Glueful\Permissions\Middleware\GateAttributeMiddleware::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Permissions\Middleware\GateAttributeMiddleware(
                $c->get('permission.manager')
            )
        );

        // Auth-to-request attributes middleware
        $defs[\Glueful\Permissions\Middleware\AuthToRequestAttributesMiddleware::class] = new FactoryDefinition(
            \Glueful\Permissions\Middleware\AuthToRequestAttributesMiddleware::class,
            fn(\Psr\Container\ContainerInterface $c) =>
                new \Glueful\Permissions\Middleware\AuthToRequestAttributesMiddleware(
                    $c->get(\Glueful\Http\RequestUserContext::class)
                )
        );

        // String alias convenience (parity with DI aliases)
        $defs['auth'] = new AliasDefinition(
            'auth',
            \Glueful\Routing\Middleware\AuthMiddleware::class
        );
        $defs['rate_limit'] = new AliasDefinition(
            'rate_limit',
            \Glueful\Api\RateLimiting\Middleware\EnhancedRateLimiterMiddleware::class
        );
        $defs['require_scope'] = new AliasDefinition(
            'require_scope',
            \Glueful\Routing\Middleware\RequireScopeMiddleware::class
        );
        $defs[\Glueful\Routing\Middleware\RequireScopeMiddleware::class] =
            $this->autowire(\Glueful\Routing\Middleware\RequireScopeMiddleware::class);
        $defs[\Glueful\Auth\ApiKey\ApiKeyService::class] =
            $this->autowire(\Glueful\Auth\ApiKey\ApiKeyService::class);
        $defs['csrf'] = new AliasDefinition(
            'csrf',
            \Glueful\Routing\Middleware\CSRFMiddleware::class
        );
        $defs['security_headers'] = new AliasDefinition(
            'security_headers',
            \Glueful\Routing\Middleware\SecurityHeadersMiddleware::class
        );
        $defs['admin'] = new AliasDefinition('admin', \Glueful\Routing\Middleware\AdminPermissionMiddleware::class);
        $defs['request_logging'] = new AliasDefinition(
            'request_logging',
            \Glueful\Routing\Middleware\RequestResponseLoggingMiddleware::class
        );
        $defs['lockdown'] = new AliasDefinition('lockdown', \Glueful\Routing\Middleware\LockdownMiddleware::class);
        $defs['allow_ip'] = new AliasDefinition('allow_ip', \Glueful\Routing\Middleware\AllowIpMiddleware::class);
        $defs['metrics'] = new AliasDefinition('metrics', \Glueful\Routing\Middleware\MetricsMiddleware::class);
        $defs['tracing'] = new AliasDefinition('tracing', \Glueful\Routing\Middleware\TracingMiddleware::class);
        $defs['conditional_cache'] = new AliasDefinition(
            'conditional_cache',
            \Glueful\Routing\Middleware\ConditionalCacheMiddleware::class
        );
        $defs['gate_permissions'] = new AliasDefinition(
            'gate_permissions',
            \Glueful\Permissions\Middleware\GateAttributeMiddleware::class
        );
        $defs['auth_to_request'] = new AliasDefinition(
            'auth_to_request',
            \Glueful\Permissions\Middleware\AuthToRequestAttributesMiddleware::class
        );
        $defs['validate'] = new AliasDefinition(
            'validate',
            \Glueful\Validation\Middleware\ValidationMiddleware::class
        );

        // ===== Enhanced Rate Limiting =====

        // Storage adapter for rate limiting (uses cache store)
        $defs[\Glueful\Api\RateLimiting\Contracts\StorageInterface::class] = new FactoryDefinition(
            \Glueful\Api\RateLimiting\Contracts\StorageInterface::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Api\RateLimiting\Storage\CacheStorage(
                $c->get('cache.store')
            )
        );

        // Tier manager for rate limit tiers
        $defs[\Glueful\Api\RateLimiting\TierManager::class] = new FactoryDefinition(
            \Glueful\Api\RateLimiting\TierManager::class,
            function () {
                $config = function_exists('config') ? (array) config($this->context, 'api.rate_limiting', []) : [];
                return new \Glueful\Api\RateLimiting\TierManager($config);
            }
        );

        // Tier resolver for determining user tier from request
        $defs[\Glueful\Api\RateLimiting\Contracts\TierResolverInterface::class] = new FactoryDefinition(
            \Glueful\Api\RateLimiting\Contracts\TierResolverInterface::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Api\RateLimiting\TierResolver(
                $c->get(\Glueful\Api\RateLimiting\TierManager::class)
            )
        );

        // Rate limit headers generator
        $defs[\Glueful\Api\RateLimiting\RateLimitHeaders::class] = new FactoryDefinition(
            \Glueful\Api\RateLimiting\RateLimitHeaders::class,
            function () {
                $config = function_exists('config')
                    ? (array) config($this->context, 'api.rate_limiting.headers', [])
                    : [];
                return new \Glueful\Api\RateLimiting\RateLimitHeaders($config);
            }
        );

        // Rate limit manager (central orchestrator)
        $defs[\Glueful\Api\RateLimiting\RateLimitManager::class] = new FactoryDefinition(
            \Glueful\Api\RateLimiting\RateLimitManager::class,
            function (\Psr\Container\ContainerInterface $c) {
                $config = function_exists('config') ? (array) config($this->context, 'api.rate_limiting', []) : [];
                return new \Glueful\Api\RateLimiting\RateLimitManager(
                    $c->get(\Glueful\Api\RateLimiting\Contracts\StorageInterface::class),
                    $c->get(\Glueful\Api\RateLimiting\Contracts\TierResolverInterface::class),
                    $c->get(\Glueful\Api\RateLimiting\TierManager::class),
                    $config
                );
            }
        );

        // Enhanced rate limiter middleware
        $defs[\Glueful\Api\RateLimiting\Middleware\EnhancedRateLimiterMiddleware::class] = new FactoryDefinition(
            \Glueful\Api\RateLimiting\Middleware\EnhancedRateLimiterMiddleware::class,
            function (\Psr\Container\ContainerInterface $c) {
                $config = function_exists('config') ? (array) config($this->context, 'api.rate_limiting', []) : [];
                return new \Glueful\Api\RateLimiting\Middleware\EnhancedRateLimiterMiddleware(
                    $c->get(\Glueful\Api\RateLimiting\RateLimitManager::class),
                    $c->get(\Glueful\Api\RateLimiting\RateLimitHeaders::class),
                    $config
                );
            }
        );

        // Alias for enhanced rate limit middleware
        $defs['enhanced_rate_limit'] = new AliasDefinition(
            'enhanced_rate_limit',
            \Glueful\Api\RateLimiting\Middleware\EnhancedRateLimiterMiddleware::class
        );

        // ===== Webhooks System =====

        // Webhook payload builder
        $defs[\Glueful\Api\Webhooks\WebhookPayload::class] =
            $this->autowire(\Glueful\Api\Webhooks\WebhookPayload::class);

        $defs[\Glueful\Api\Webhooks\Contracts\WebhookPayloadInterface::class] = new AliasDefinition(
            \Glueful\Api\Webhooks\Contracts\WebhookPayloadInterface::class,
            \Glueful\Api\Webhooks\WebhookPayload::class
        );

        // Webhook dispatcher (with auto-migration)
        $defs[\Glueful\Api\Webhooks\WebhookDispatcher::class] = new FactoryDefinition(
            \Glueful\Api\Webhooks\WebhookDispatcher::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Api\Webhooks\WebhookDispatcher(
                $c->get('database'),
                $c->get(\Glueful\Api\Webhooks\Contracts\WebhookPayloadInterface::class),
                $this->context
            )
        );

        $defs[\Glueful\Api\Webhooks\Contracts\WebhookDispatcherInterface::class] = new AliasDefinition(
            \Glueful\Api\Webhooks\Contracts\WebhookDispatcherInterface::class,
            \Glueful\Api\Webhooks\WebhookDispatcher::class
        );

        // Webhook event listener
        $defs[\Glueful\Api\Webhooks\Listeners\WebhookEventListener::class] = new FactoryDefinition(
            \Glueful\Api\Webhooks\Listeners\WebhookEventListener::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Api\Webhooks\Listeners\WebhookEventListener(
                $c->get(\Glueful\Api\Webhooks\Contracts\WebhookDispatcherInterface::class)
            )
        );

        // Webhook controller
        $defs[\Glueful\Api\Webhooks\Http\Controllers\WebhookController::class] = new FactoryDefinition(
            \Glueful\Api\Webhooks\Http\Controllers\WebhookController::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Api\Webhooks\Http\Controllers\WebhookController(
                $this->context,
                $c->get(\Glueful\Api\Webhooks\Contracts\WebhookDispatcherInterface::class)
            )
        );

        // ===== Search & Filtering DSL =====

        // Filter parser
        $defs[\Glueful\Api\Filtering\FilterParser::class] = new FactoryDefinition(
            \Glueful\Api\Filtering\FilterParser::class,
            function () {
                $config = function_exists('config') ? (array) config($this->context, 'api.filtering', []) : [];
                return new \Glueful\Api\Filtering\FilterParser(
                    (int) ($config['max_depth'] ?? 3),
                    (int) ($config['max_filters'] ?? 20)
                );
            }
        );

        // Filter middleware
        $defs[\Glueful\Api\Filtering\Middleware\FilterMiddleware::class] =
            $this->autowire(\Glueful\Api\Filtering\Middleware\FilterMiddleware::class);

        // Middleware alias for filtering
        $defs['filter'] = new AliasDefinition(
            'filter',
            \Glueful\Api\Filtering\Middleware\FilterMiddleware::class
        );

        // Encryption service
        $defs[\Glueful\Encryption\EncryptionService::class] = new FactoryDefinition(
            \Glueful\Encryption\EncryptionService::class,
            fn() => new \Glueful\Encryption\EncryptionService($this->context)
        );

        return $defs;
    }
}
