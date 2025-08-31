<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\Container;
use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Guarded Auto-Configuration Provider
 *
 * Registers optional integrations when the underlying extensions/classes exist
 * and feature flags are enabled. This provider is strictly additive and never
 * blocks boot if dependencies are missing.
 */
final class AutoConfigServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $container): void
    {
        $this->registerRedis($container);
        $this->registerMailer($container);
        $this->registerLdap($container);
    }

    public function boot(Container $container): void
    {
        // No boot-time work required; definitions are lazy
        unset($container);
    }

    public function getCompilerPasses(): array
    {
        return [];
    }

    public function getName(): string
    {
        return 'auto_config';
    }

    private function registerRedis(ContainerBuilder $container): void
    {
        if (!extension_loaded('redis')) {
            return;
        }

        // Only wire if Redis is the intended cache or explicitly enabled
        $default = (string) (config('cache.default', 'redis'));
        $explicit = (bool) config('cache.stores.redis.enabled', true);
        if ($default !== 'redis' && !$explicit) {
            return;
        }

        if (!class_exists(\Redis::class)) {
            return;
        }

        $def = new Definition(\Redis::class);
        $def->setFactory([self::class, 'createRedis']);
        $def->setPublic(true);
        $container->setDefinition('redis', $def);
    }

    /**
     * Factory for Redis configured from cache.stores.redis.* or env
     */
    public static function createRedis(): \Redis
    {
        $redis = new \Redis();
        $host = config('cache.stores.redis.host') ?: env('REDIS_HOST', '127.0.0.1');
        $port = (int)(config('cache.stores.redis.port') ?: env('REDIS_PORT', 6379));
        $timeout = (float)(config('cache.stores.redis.timeout') ?: env('REDIS_TIMEOUT', 2.5));
        $password = config('cache.stores.redis.password') ?: env('REDIS_PASSWORD', null);
        $redis->connect($host, $port, $timeout);
        if (!empty($password)) {
            $redis->auth($password);
        }
        $db = (int)(config('cache.stores.redis.database') ?: env('REDIS_DB', 0));
        if ($db > 0) {
            $redis->select($db);
        }
        return $redis;
    }

    private function registerMailer(ContainerBuilder $container): void
    {
        if (!class_exists(\Symfony\Component\Mailer\Mailer::class)) {
            return;
        }

        // Read consolidated config from services.php
        $defaultMailer = config('services.mail.default', null);
        $mailers = (array) config('services.mail.mailers', []);

        // If no mail configuration present, skip wiring
        if ($defaultMailer === null || !isset($mailers[$defaultMailer])) {
            // Fallback to explicit DSN if provided via env
            $fallbackDsn = env('MAILER_DSN');
            if (!$fallbackDsn) {
                return;
            }
            $dsn = (string) $fallbackDsn;
        } else {
            $mailerConfig = (array) $mailers[$defaultMailer];
            $dsn = (string) ($mailerConfig['dsn'] ?? '');
            if ($dsn === '') {
                $dsn = self::buildMailerDsn($mailerConfig, (string) $defaultMailer);
            }
        }

        // Register Transport from resolved DSN
        $transportDef = new Definition(\Symfony\Component\Mailer\Transport\TransportInterface::class);
        $transportDef->setFactory([\Symfony\Component\Mailer\Transport::class, 'fromDsn']);
        $transportDef->setArguments([$dsn]);
        $transportDef->setPublic(true);
        $container->setDefinition('mailer.transport', $transportDef);

        $mailerDef = new Definition(\Symfony\Component\Mailer\Mailer::class);
        $mailerDef->setArguments([new \Symfony\Component\DependencyInjection\Reference('mailer.transport')]);
        $mailerDef->setPublic(true);
        $container->setDefinition('mailer', $mailerDef);

        // Optional alias
        $container->setAlias(\Symfony\Component\Mailer\MailerInterface::class, 'mailer')->setPublic(true);
    }

    /**
     * Build a Symfony Mailer DSN from a mailer config entry.
     */
    private static function buildMailerDsn(array $cfg, string $name): string
    {
        $transport = (string) ($cfg['transport'] ?? 'smtp');
        $transport = trim($transport);

        // Common simple transports
        if ($transport === 'null') {
            return 'null://default';
        }
        if ($transport === 'array') {
            return 'array://default';
        }
        if ($transport === 'log' || $transport === 'logger') {
            $channel = (string) ($cfg['channel'] ?? 'mail');
            return 'logger://default?channel=' . rawurlencode($channel);
        }

        // SMTP builder
        if ($transport === 'smtp') {
            $host = (string) ($cfg['host'] ?? 'localhost');
            $port = (int) ($cfg['port'] ?? 25);
            $user = isset($cfg['username']) ? (string) $cfg['username'] : '';
            $pass = isset($cfg['password']) ? (string) $cfg['password'] : '';
            $enc  = isset($cfg['encryption']) ? (string) $cfg['encryption'] : '';

            $auth = $user !== '' ? rawurlencode($user) . ':' . rawurlencode($pass) . '@' : '';
            $query = $enc !== '' ? '?encryption=' . rawurlencode($enc) : '';
            return "smtp://{$auth}{$host}:{$port}{$query}";
        }

        // Provider-specific +api transports (sendgrid+api, mailgun+api, ses+api, postmark+api, brevo+api, etc)
        $dsn = (string) ($cfg['dsn'] ?? '');
        if ($dsn !== '') {
            return $dsn;
        }

        // Try key/token-based API DSN
        $key = $cfg['key'] ?? $cfg['token'] ?? null;
        if (!empty($key)) {
            return $transport . '://' . rawurlencode((string) $key) . '@default';
        }

        // Last resort
        return $transport . '://default';
    }

    private function registerLdap(ContainerBuilder $container): void
    {
        if (!extension_loaded('ldap') || !class_exists(\LdapRecord\Connection::class)) {
            return;
        }
        if (!(bool) config('auth.ldap.enabled', false)) {
            return;
        }

        $def = new Definition(\LdapRecord\Connection::class);
        $def->setArguments([ (array) config('auth.ldap', []) ]);
        $def->setPublic(true);
        $container->setDefinition('ldap', $def);
    }
}
