<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceFactories;

use Glueful\Bootstrap\ConfigurationCache;
use Glueful\Auth\AuthenticationManager;
use Glueful\Auth\JwtAuthenticationProvider;
use Glueful\Auth\LdapAuthenticationProvider;
use Glueful\Auth\SamlAuthenticationProvider;
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
                'ldap' => $manager->registerProvider('ldap', new LdapAuthenticationProvider()),
                'saml' => $manager->registerProvider('saml', new SamlAuthenticationProvider()),
                'apikey' => $manager->registerProvider('apikey', new ApiKeyAuthenticationProvider()),
                default => null, // Skip unknown providers
            };
        }

        return $manager;
    }
}
