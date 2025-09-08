<?php

/**
 * AuthMiddleware Usage Examples
 * 
 * Comprehensive examples of using the upgraded AuthMiddleware with
 * the next-gen router system and all configurable options.
 */

use Glueful\Routing\Middleware\AuthMiddleware;
use Glueful\Auth\AuthenticationManager;
use Glueful\DI\Container;
use Psr\Log\LoggerInterface;

// ============================================================================
// EXAMPLE 1: Basic Usage (Default Configuration)
// ============================================================================

// Simple authentication - uses defaults (JWT + API key providers)
$router->get('/api/profile', 'UserController@getProfile')
    ->middleware([new AuthMiddleware()]);

// Admin-only route - requires 'admin' parameter
$router->post('/api/admin/users', 'AdminController@createUser')
    ->middleware([new AuthMiddleware(), 'admin']);

// ============================================================================
// EXAMPLE 2: Custom Provider Configuration
// ============================================================================

// Only allow JWT authentication
$jwtOnlyMiddleware = new AuthMiddleware(
    authManager: null,
    container: null,
    providerNames: ['jwt']
);

$router->get('/api/jwt-only', 'ApiController@getData')
    ->middleware([$jwtOnlyMiddleware]);

// Multiple specific providers in order
$multiProviderMiddleware = new AuthMiddleware(
    authManager: null,
    container: null,
    providerNames: ['jwt', 'api_key', 'ldap', 'saml']
);

$router->get('/api/enterprise-auth', 'EnterpriseController@getData')
    ->middleware([$multiProviderMiddleware]);

// ============================================================================
// EXAMPLE 3: Full Configuration with Options
// ============================================================================

$enterpriseMiddleware = new AuthMiddleware(
    authManager: null, // Will use container or AuthBootstrap
    container: null,   // Will auto-detect container
    providerNames: ['jwt', 'api_key', 'ldap'],
    options: [
        'validate_expiration' => true,    // Enable token expiration checks
        'enable_events' => true,          // Dispatch auth success/failure events
        'enable_logging' => true          // Enable detailed PSR logging
    ]
);

$router->group([
    'prefix' => '/api/v1',
    'middleware' => [$enterpriseMiddleware]
], function($router) {
    $router->get('/users', 'UserController@index');
    $router->post('/users', 'UserController@create')->middleware(['admin']);
    $router->put('/users/{id}', 'UserController@update');
});

// ============================================================================
// EXAMPLE 4: Custom Dependencies (Advanced)
// ============================================================================

// Custom authentication manager and container
$customAuthManager = new AuthenticationManager();
$customAuthManager->registerProvider('custom_jwt', new CustomJwtProvider());
$customAuthManager->registerProvider('oauth2', new OAuth2Provider());

$container = app(); // Get your DI container

$advancedMiddleware = new AuthMiddleware(
    authManager: $customAuthManager,
    container: $container,
    providerNames: ['custom_jwt', 'oauth2'],
    options: [
        'validate_expiration' => true,
        'enable_events' => true,
        'enable_logging' => true
    ]
);

$router->group([
    'prefix' => '/api/oauth',
    'middleware' => [$advancedMiddleware]
], function($router) {
    $router->get('/profile', 'OAuth2Controller@profile');
    $router->get('/admin/settings', 'OAuth2Controller@adminSettings')->middleware(['admin']);
});

// ============================================================================
// EXAMPLE 5: Environment-Specific Configuration
// ============================================================================

// Development - verbose logging, no expiration validation
if (config('app.env') === 'development') {
    $devMiddleware = new AuthMiddleware(
        options: [
            'validate_expiration' => false,  // Skip expiration in dev
            'enable_events' => false,        // Skip events in dev
            'enable_logging' => true         // But keep logging for debugging
        ]
    );
    
    $router->group(['middleware' => [$devMiddleware]], function($router) {
        $router->get('/api/dev/test', 'DevController@test');
    });
}

// Production - full security features enabled
if (config('app.env') === 'production') {
    $prodMiddleware = new AuthMiddleware(
        providerNames: ['jwt', 'api_key'],
        options: [
            'validate_expiration' => true,   // Strict expiration checking
            'enable_events' => true,         // Full event dispatching
            'enable_logging' => true         // Comprehensive logging
        ]
    );
    
    $router->group(['middleware' => [$prodMiddleware]], function($router) {
        $router->get('/api/secure-data', 'SecureController@getData');
    });
}

// ============================================================================
// EXAMPLE 6: Feature-Specific Configurations
// ============================================================================

// API Gateway - events disabled for performance, logging enabled for monitoring
$apiGatewayMiddleware = new AuthMiddleware(
    providerNames: ['api_key'],
    options: [
        'validate_expiration' => true,
        'enable_events' => false,    // Disable events for high-throughput
        'enable_logging' => true     // Keep logging for monitoring
    ]
);

$router->group([
    'prefix' => '/gateway',
    'middleware' => [$apiGatewayMiddleware]
], function($router) {
    $router->get('/data', 'GatewayController@getData');
    $router->post('/webhook', 'GatewayController@webhook');
});

// WebSocket/SSE endpoints - token expiration disabled (long-lived connections)
$websocketMiddleware = new AuthMiddleware(
    providerNames: ['jwt'],
    options: [
        'validate_expiration' => false,  // Allow longer-lived connections
        'enable_events' => true,
        'enable_logging' => false        // Reduce logging noise
    ]
);

$router->get('/api/websocket/connect', 'WebSocketController@connect')
    ->middleware([$websocketMiddleware]);

// ============================================================================
// EXAMPLE 7: Admin-Only Routes with Custom Configuration
// ============================================================================

$adminMiddleware = new AuthMiddleware(
    providerNames: ['jwt', 'ldap'], // Admin users might use LDAP
    options: [
        'validate_expiration' => true,   // Strict for admin access
        'enable_events' => true,         // Log admin actions
        'enable_logging' => true
    ]
);

$router->group([
    'prefix' => '/admin',
    'middleware' => [$adminMiddleware, 'admin'] // Both middleware and admin param
], function($router) {
    $router->get('/dashboard', 'AdminController@dashboard');
    $router->post('/users', 'AdminController@createUser');
    $router->delete('/users/{id}', 'AdminController@deleteUser');
    $router->get('/system-logs', 'AdminController@systemLogs');
});

// ============================================================================
// EXAMPLE 8: Multi-Tenant Configuration
// ============================================================================

// Different tenants might use different authentication providers
$tenant1Middleware = new AuthMiddleware(
    providerNames: ['jwt'],  // Simple JWT for basic tenants
    options: ['validate_expiration' => true, 'enable_events' => true, 'enable_logging' => true]
);

$tenant2Middleware = new AuthMiddleware(
    providerNames: ['saml', 'ldap'],  // Enterprise auth for premium tenants
    options: ['validate_expiration' => true, 'enable_events' => true, 'enable_logging' => true]
);

$router->group([
    'prefix' => '/tenant/basic',
    'middleware' => [$tenant1Middleware]
], function($router) {
    $router->get('/data', 'TenantController@basicData');
});

$router->group([
    'prefix' => '/tenant/enterprise',
    'middleware' => [$tenant2Middleware]
], function($router) {
    $router->get('/data', 'TenantController@enterpriseData');
    $router->post('/admin-action', 'TenantController@adminAction')->middleware(['admin']);
});

// ============================================================================
// EXAMPLE 9: Conditional Middleware Based on Route Patterns
// ============================================================================

// Public API - no expiration validation for better UX
$publicApiMiddleware = new AuthMiddleware(
    providerNames: ['api_key'],
    options: [
        'validate_expiration' => false,  // More lenient for public API
        'enable_events' => false,        // Less noise
        'enable_logging' => true         // But track usage
    ]
);

// Internal API - strict validation
$internalApiMiddleware = new AuthMiddleware(
    providerNames: ['jwt'],
    options: [
        'validate_expiration' => true,   // Strict for internal use
        'enable_events' => true,
        'enable_logging' => true
    ]
);

$router->group(['prefix' => '/api/public', 'middleware' => [$publicApiMiddleware]], function($router) {
    $router->get('/status', 'PublicController@status');
    $router->get('/info', 'PublicController@info');
});

$router->group(['prefix' => '/api/internal', 'middleware' => [$internalApiMiddleware]], function($router) {
    $router->get('/metrics', 'InternalController@metrics');
    $router->post('/cache/clear', 'InternalController@clearCache')->middleware(['admin']);
});

// ============================================================================
// EXAMPLE 10: Factory Pattern for Middleware Creation
// ============================================================================

class AuthMiddlewareFactory
{
    public static function createForEnvironment(string $env): AuthMiddleware
    {
        $options = match($env) {
            'development' => [
                'validate_expiration' => false,
                'enable_events' => false,
                'enable_logging' => true
            ],
            'testing' => [
                'validate_expiration' => false,
                'enable_events' => false,
                'enable_logging' => false
            ],
            'staging' => [
                'validate_expiration' => true,
                'enable_events' => true,
                'enable_logging' => true
            ],
            'production' => [
                'validate_expiration' => true,
                'enable_events' => true,
                'enable_logging' => true
            ],
            default => [
                'validate_expiration' => true,
                'enable_events' => true,
                'enable_logging' => true
            ]
        };

        return new AuthMiddleware(
            providerNames: ['jwt', 'api_key'],
            options: $options
        );
    }

    public static function createForTenant(string $tenantType): AuthMiddleware
    {
        $providerNames = match($tenantType) {
            'basic' => ['jwt'],
            'premium' => ['jwt', 'api_key'],
            'enterprise' => ['jwt', 'api_key', 'ldap', 'saml'],
            default => ['jwt']
        };

        return new AuthMiddleware(
            providerNames: $providerNames,
            options: [
                'validate_expiration' => true,
                'enable_events' => true,
                'enable_logging' => true
            ]
        );
    }
}

// Usage with factory
$envMiddleware = AuthMiddlewareFactory::createForEnvironment(config('app.env'));
$router->group(['middleware' => [$envMiddleware]], function($router) {
    $router->get('/api/user', 'UserController@index');
});

// ============================================================================
// EXAMPLE 11: Response Examples - What Users Will See
// ============================================================================

/*
 * SUCCESS RESPONSE (authenticated user):
 * HTTP 200 OK
 * Request continues to controller...
 * 
 * FAILURE RESPONSES:
 * 
 * 1. Missing token:
 * HTTP 401 Unauthorized
 * {
 *   "success": false,
 *   "message": "Authentication required",
 *   "code": 401,
 *   "error_code": "UNAUTHORIZED"
 * }
 * 
 * 2. Expired token (refresh available):
 * HTTP 401 Unauthorized  
 * {
 *   "success": false,
 *   "message": "Access token expired",
 *   "code": 401,
 *   "error_code": "TOKEN_EXPIRED",
 *   "refresh_available": true
 * }
 * 
 * 3. Session expired:
 * HTTP 401 Unauthorized
 * {
 *   "success": false,
 *   "message": "Session expired. Please log in again",
 *   "code": 401,
 *   "error_code": "SESSION_EXPIRED", 
 *   "refresh_available": false
 * }
 * 
 * 4. Admin access required:
 * HTTP 403 Forbidden
 * {
 *   "success": false,
 *   "message": "Admin access required",
 *   "code": 403,
 *   "error_code": "FORBIDDEN"
 * }
 * 
 * 5. Invalid token format:
 * HTTP 401 Unauthorized
 * {
 *   "success": false,
 *   "message": "Invalid token format", 
 *   "code": 401,
 *   "error_code": "INVALID_TOKEN"
 * }
 */

// ============================================================================
// EXAMPLE 12: Event Listeners (Optional - if events are enabled)
// ============================================================================

/*
 * You can listen for authentication events in your application:
 * 
 * Event::listen(HttpAuthSuccessEvent::class, function($event) {
 *     // Log successful authentication
 *     Log::info('User authenticated', [
 *         'user_id' => $event->getMetadata()['user_id'] ?? null,
 *         'provider' => $event->getMetadata()['provider'] ?? 'unknown',
 *         'path' => $event->getRequest()->getPathInfo()
 *     ]);
 * });
 * 
 * Event::listen(HttpAuthFailureEvent::class, function($event) {
 *     // Track failed authentication attempts
 *     Log::warning('Authentication failed', [
 *         'reason' => $event->getReason(),
 *         'ip' => $event->getRequest()->getClientIp(),
 *         'path' => $event->getRequest()->getPathInfo()
 *     ]);
 * });
 */