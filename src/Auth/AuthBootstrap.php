<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Auth\Interfaces\AuthenticationProviderInterface;
use Glueful\Bootstrap\ApplicationContext;

/**
 * Authentication Bootstrapper
 *
 * Configures and initializes the authentication system.
 * Creates a singleton AuthenticationManager instance with registered providers.
 */
class AuthBootstrap
{
    private ?AuthenticationManager $manager = null;

    public function __construct(
        private ApplicationContext $context
    ) {
    }

    /**
     * Initialize the authentication system
     *
     * Creates and configures the AuthenticationManager with available providers.
     *
     * @return AuthenticationManager The configured authentication manager
     */
    public function initialize(): AuthenticationManager
    {
        if ($this->manager !== null) {
            return $this->manager;
        }

        // Create default authentication providers
        $jwtProvider = new JwtAuthenticationProvider($this->context);
        $apiKeyProvider = new ApiKeyAuthenticationProvider($this->context);

        // Create manager with JWT provider as default
        $manager = new AuthenticationManager($jwtProvider);

        // Register additional providers
        $manager->registerProvider('jwt', $jwtProvider);
        $manager->registerProvider('api_key', $apiKeyProvider);

        // Register additional custom providers
        $this->registerCustomProviders($manager);

        // Store the instance
        $this->manager = $manager;

        // Give providers access to the manager for admin checks
        $jwtProvider->setAuthManager($manager);
        $apiKeyProvider->setAuthManager($manager);

        return $manager;
    }

    /**
     * Register custom authentication providers from configuration
     *
     * @param AuthenticationManager $manager The manager to configure
     */
    private function registerCustomProviders(AuthenticationManager $manager): void
    {
        // Get configured providers from configuration
        $configuredProviders = [];
        if (function_exists('config')) {
            $configuredProviders = (array) config($this->context, 'session.providers', []);
        }

        foreach ($configuredProviders as $name => $providerClass) {
            // Skip if already registered
            if ($manager->getProvider($name) !== null) {
                continue;
            }

            try {
                // Skip if the class doesn't exist
                if (!class_exists($providerClass)) {
                    continue;
                }

                // Create and register the provider if it implements the interface
                $provider = new $providerClass();
                if ($provider instanceof AuthenticationProviderInterface) {
                    $manager->registerProvider($name, $provider);
                }
            } catch (\Throwable $e) {
                // Log error and continue with other providers
                error_log("Failed to register authentication provider '{$name}': " . $e->getMessage());
            }
        }
    }

    /**
     * Get the authentication manager instance
     *
     * @return AuthenticationManager The manager instance
     */
    public function getManager(): AuthenticationManager
    {
        return $this->manager ?? $this->initialize();
    }
}
