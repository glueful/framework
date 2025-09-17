<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceFactories;

use Glueful\Bootstrap\ConfigurationCache;
use Glueful\Auth\AuthenticationManager;
use Glueful\Auth\JwtAuthenticationProvider;
use Glueful\Auth\ApiKeyAuthenticationProvider;

class AuthManagerFactory
{
    public static function create(): AuthenticationManager
    {
        $config = ConfigurationCache::get('auth', []);

        $manager = new AuthenticationManager();

        // Register authentication providers based on config
        $providers = $config['providers'] ?? ['jwt'];

        foreach ($providers as $provider) {
            match ($provider) {
                'jwt' => $manager->registerProvider('jwt', new JwtAuthenticationProvider()),
                'apikey' => $manager->registerProvider('apikey', new ApiKeyAuthenticationProvider()),
                default => null, // Skip unknown providers (including 'ldap', 'saml' - must be registered by extensions)
            };
        }

        return $manager;
    }
}
