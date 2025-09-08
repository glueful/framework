<?php

/**
 * Middleware Usage Comparison: Container vs Direct Instantiation
 */

// ============================================================================
// METHOD 1: Using Container-Registered Middleware (String Aliases)
// ============================================================================

// These work because AuthMiddleware is registered in CoreServiceProvider
$router->get('/api/profile', 'UserController@getProfile')
    ->middleware(['auth']);  // Uses container-registered AuthMiddleware

$router->post('/api/admin/users', 'AdminController@createUser')
    ->middleware(['auth', 'admin']); // 'auth' + 'admin' parameter

$router->group(['middleware' => ['auth']], function($router) {
    $router->get('/dashboard', 'DashboardController@index');
    $router->get('/settings', 'SettingsController@index');
});

// ============================================================================
// METHOD 2: Direct Instantiation with Custom Configuration  
// ============================================================================

// Custom configuration - different from framework defaults
$apiAuthMiddleware = new \Glueful\Routing\Middleware\AuthMiddleware(
    authManager: null,
    container: null,
    providerNames: ['api_key'], // Only API keys for this endpoint
    options: [
        'validate_expiration' => false,  // Lenient for API
        'enable_events' => false,        // High performance
        'enable_logging' => true         // But track usage
    ]
);

$router->get('/api/public-data', 'PublicApiController@getData')
    ->middleware([$apiAuthMiddleware]);

// Different configuration for internal API
$internalAuthMiddleware = new \Glueful\Routing\Middleware\AuthMiddleware(
    providerNames: ['jwt'],
    options: [
        'validate_expiration' => true,   // Strict for internal
        'enable_events' => true,
        'enable_logging' => true
    ]
);

$router->group(['middleware' => [$internalAuthMiddleware]], function($router) {
    $router->get('/internal/metrics', 'InternalController@metrics');
    $router->post('/internal/admin-action', 'InternalController@adminAction')
        ->middleware(['admin']); // Can still use string for 'admin' parameter
});

// ============================================================================
// METHOD 3: Mixed Usage (Recommended Pattern)
// ============================================================================

// Use string aliases for standard cases
$router->group(['middleware' => ['auth']], function($router) {
    
    // Standard routes use framework defaults
    $router->get('/profile', 'UserController@profile');
    $router->get('/dashboard', 'DashboardController@index');
    
    // Special routes use custom middleware
    $router->get('/experimental-feature', 'ExperimentalController@index')
        ->middleware([
            new \App\Middleware\FeatureFlagMiddleware('experimental_ui'),
            new \App\Middleware\ABTestMiddleware()
        ]);
});

// ============================================================================
// HOW CONTAINER REGISTRATION WORKS INTERNALLY
// ============================================================================

/*
 * In CoreServiceProvider.php:
 * 
 * $container->register(\Glueful\Routing\Middleware\AuthMiddleware::class)
 *     ->setArguments([new Reference(\Glueful\Auth\AuthenticationManager::class)])
 *     ->setPublic(true);
 * 
 * $container->setAlias('auth', \Glueful\Routing\Middleware\AuthMiddleware::class)
 *     ->setPublic(true);
 * 
 * This means when you use 'auth' string, the router does:
 * 1. Resolves 'auth' to \Glueful\Routing\Middleware\AuthMiddleware::class
 * 2. Container automatically injects \Glueful\Auth\AuthenticationManager
 * 3. Creates instance with framework defaults:
 *    - providerNames: ['jwt', 'api_key'] (default)
 *    - options: all enabled (default)
 * 
 * Equivalent to:
 * new AuthMiddleware(
 *     authManager: $container->get(AuthenticationManager::class),
 *     container: $container,
 *     providerNames: ['jwt', 'api_key'],  // framework default
 *     options: [
 *         'validate_expiration' => true,
 *         'enable_events' => true, 
 *         'enable_logging' => true
 *     ]
 * );
 */

// ============================================================================
// REGISTERING YOUR OWN MIDDLEWARE IN CONTAINER (Advanced)
// ============================================================================

/*
 * If you want to register your custom middleware for string usage:
 * 
 * // In your ServiceProvider
 * class AppServiceProvider implements ServiceProviderInterface
 * {
 *     public function register(ContainerBuilder $container): void
 *     {
 *         // Register custom middleware
 *         $container->register(\App\Middleware\RateLimitMiddleware::class)
 *             ->setArguments([1000, 3600]) // maxRequests, windowSeconds
 *             ->setPublic(true);
 * 
 *         // Create string alias
 *         $container->setAlias('rate_limit', \App\Middleware\RateLimitMiddleware::class)
 *             ->setPublic(true);
 *     }
 * }
 * 
 * // Then use with string:
 * $router->get('/api/data', 'ApiController@getData')
 *     ->middleware(['rate_limit', 'auth']);
 */

// ============================================================================
// SUMMARY: WHEN TO USE WHICH APPROACH
// ============================================================================

/*
 * USE STRING ALIASES ('auth') WHEN:
 * ✅ Framework defaults are sufficient
 * ✅ You want clean, readable route definitions  
 * ✅ Standard authentication behavior is desired
 * ✅ You're building typical CRUD applications
 * 
 * Examples:
 * - Basic user authentication
 * - Standard admin routes
 * - Simple API endpoints
 * 
 * USE DIRECT INSTANTIATION (new AuthMiddleware()) WHEN:
 * ✅ You need custom configuration
 * ✅ Different routes need different auth behavior
 * ✅ You're using custom authentication providers
 * ✅ You want runtime flexibility
 * ✅ You're creating user-defined middleware
 * 
 * Examples:
 * - Multi-tenant applications (different auth per tenant)
 * - API gateways with custom rate limiting
 * - Development vs production configurations
 * - A/B testing different auth flows
 * - Custom business logic middleware
 */