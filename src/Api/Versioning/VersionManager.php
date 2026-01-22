<?php

declare(strict_types=1);

namespace Glueful\Api\Versioning;

use Glueful\Api\Versioning\Contracts\VersionNegotiatorInterface;
use Glueful\Api\Versioning\Contracts\VersionResolverInterface;
use Glueful\Api\Versioning\Resolvers\UrlPrefixResolver;
use Glueful\Api\Versioning\Resolvers\HeaderResolver;
use Glueful\Api\Versioning\Resolvers\QueryParameterResolver;
use Glueful\Api\Versioning\Resolvers\AcceptHeaderResolver;
use Symfony\Component\HttpFoundation\Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Central manager for API versioning
 *
 * Handles version negotiation, deprecation tracking, and sunset management.
 * Supports multiple resolution strategies with configurable priority.
 */
final class VersionManager implements VersionNegotiatorInterface
{
    /** @var array<VersionResolverInterface> */
    private array $resolvers = [];

    /** @var bool Whether resolvers need sorting */
    private bool $resolversSorted = false;

    /**
     * Version status tracking
     *
     * @var array<string, array{
     *     deprecated: bool,
     *     sunset: ?\DateTimeImmutable,
     *     message: ?string,
     *     alternative: ?string
     * }>
     */
    private array $versionStatus = [];

    /** @var array<string> */
    private array $supportedVersions = [];

    private LoggerInterface $logger;

    public function __construct(
        private readonly ApiVersion $defaultVersion,
        private readonly bool $strictMode = false,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Create VersionManager from configuration array
     *
     * @param array<string, mixed> $config Configuration array
     * @param LoggerInterface|null $logger Optional logger
     */
    public static function fromConfig(array $config, ?LoggerInterface $logger = null): self
    {
        $defaultVersion = ApiVersion::fromString((string) ($config['default'] ?? '1'));
        $strictMode = (bool) ($config['strict'] ?? false);

        $manager = new self($defaultVersion, $strictMode, $logger);

        // Register supported versions
        $supported = (array) ($config['supported'] ?? []);
        foreach ($supported as $version) {
            $manager->registerSupportedVersion((string) $version);
        }

        // Register deprecated versions
        $deprecated = (array) ($config['deprecated'] ?? []);
        foreach ($deprecated as $version => $info) {
            $sunsetDate = null;
            $message = null;
            $alternative = null;

            if (is_array($info)) {
                if (isset($info['sunset'])) {
                    $sunsetDate = new \DateTimeImmutable($info['sunset']);
                }
                $message = $info['message'] ?? null;
                $alternative = $info['alternative'] ?? null;
            }

            $manager->deprecateVersion((string) $version, $sunsetDate, $message, $alternative);
        }

        // Register resolvers based on configuration
        $resolvers = (array) ($config['resolvers'] ?? ['url_prefix', 'header', 'query', 'accept']);
        $resolverOptions = (array) ($config['resolver_options'] ?? []);

        foreach ($resolvers as $resolver) {
            $options = (array) ($resolverOptions[$resolver] ?? []);
            $manager->registerResolver(match ($resolver) {
                'url_prefix' => new UrlPrefixResolver(
                    (string) ($options['prefix'] ?? '/api'),
                    (int) ($options['priority'] ?? 100)
                ),
                'header' => new HeaderResolver(
                    (string) ($options['name'] ?? 'X-Api-Version'),
                    (int) ($options['priority'] ?? 80)
                ),
                'query' => new QueryParameterResolver(
                    (string) ($options['name'] ?? 'api-version'),
                    (int) ($options['priority'] ?? 60)
                ),
                'accept' => new AcceptHeaderResolver(
                    (string) ($options['vendor'] ?? 'glueful'),
                    (int) ($options['priority'] ?? 70)
                ),
                default => throw new \InvalidArgumentException("Unknown resolver type: {$resolver}")
            });
        }

        return $manager;
    }

    public function registerResolver(VersionResolverInterface $resolver): void
    {
        $this->resolvers[] = $resolver;
        $this->resolversSorted = false;
    }

    /**
     * Register a supported version
     */
    public function registerSupportedVersion(string $version): void
    {
        $normalized = ApiVersion::fromString($version)->major;
        if (!in_array($normalized, $this->supportedVersions, true)) {
            $this->supportedVersions[] = $normalized;
        }
    }

    /**
     * Mark a version as deprecated
     *
     * @param string $version Version to deprecate
     * @param \DateTimeImmutable|null $sunsetDate When the version will be removed
     * @param string|null $message Deprecation message
     * @param string|null $alternative URL of the replacement endpoint
     */
    public function deprecateVersion(
        string $version,
        ?\DateTimeImmutable $sunsetDate = null,
        ?string $message = null,
        ?string $alternative = null
    ): void {
        $normalized = ApiVersion::fromString($version)->major;
        $this->versionStatus[$normalized] = [
            'deprecated' => true,
            'sunset' => $sunsetDate,
            'message' => $message,
            'alternative' => $alternative,
        ];
    }

    public function negotiate(Request $request): ApiVersion
    {
        $this->sortResolvers();

        foreach ($this->resolvers as $resolver) {
            $version = $resolver->resolve($request);

            if ($version !== null) {
                $this->logger->debug('API version resolved', [
                    'version' => $version->toString(),
                    'resolver' => $resolver->getName(),
                    'path' => $request->getPathInfo(),
                ]);

                // Validate version is supported in strict mode
                if ($this->strictMode && !$this->isSupported($version)) {
                    $this->logger->warning('Unsupported API version requested', [
                        'version' => $version->toString(),
                        'supported' => $this->supportedVersions,
                    ]);
                    continue;
                }

                return $version;
            }
        }

        $this->logger->debug('Using default API version', [
            'version' => $this->defaultVersion->toString(),
            'path' => $request->getPathInfo(),
        ]);

        return $this->defaultVersion;
    }

    public function isSupported(ApiVersion $version): bool
    {
        if (count($this->supportedVersions) === 0) {
            return true; // No restrictions when no versions explicitly configured
        }

        return in_array($version->major, $this->supportedVersions, true);
    }

    public function isDeprecated(ApiVersion $version): bool
    {
        $normalized = $version->major;

        if (isset($this->versionStatus[$normalized])) {
            return $this->versionStatus[$normalized]['deprecated'];
        }

        return false;
    }

    public function getSunsetDate(ApiVersion $version): ?\DateTimeImmutable
    {
        $normalized = $version->major;

        if (isset($this->versionStatus[$normalized])) {
            return $this->versionStatus[$normalized]['sunset'];
        }

        return null;
    }

    /**
     * Get deprecation message for a version
     */
    public function getDeprecationMessage(ApiVersion $version): ?string
    {
        $normalized = $version->major;

        if (isset($this->versionStatus[$normalized])) {
            return $this->versionStatus[$normalized]['message'];
        }

        return null;
    }

    /**
     * Get alternative URL for a deprecated version
     */
    public function getAlternativeUrl(ApiVersion $version): ?string
    {
        $normalized = $version->major;

        if (isset($this->versionStatus[$normalized])) {
            return $this->versionStatus[$normalized]['alternative'];
        }

        return null;
    }

    public function getDefaultVersion(): ApiVersion
    {
        return $this->defaultVersion;
    }

    public function getSupportedVersions(): array
    {
        return $this->supportedVersions;
    }

    /**
     * Get all deprecated versions with their status
     *
     * @return array<string, array{
     *     deprecated: bool,
     *     sunset: ?\DateTimeImmutable,
     *     message: ?string,
     *     alternative: ?string
     * }>
     */
    public function getDeprecatedVersions(): array
    {
        return array_filter(
            $this->versionStatus,
            fn(array $status) => $status['deprecated']
        );
    }

    /**
     * Get all registered resolvers
     *
     * @return array<VersionResolverInterface>
     */
    public function getResolvers(): array
    {
        $this->sortResolvers();
        return $this->resolvers;
    }

    /**
     * Check if strict mode is enabled
     */
    public function isStrictMode(): bool
    {
        return $this->strictMode;
    }

    /**
     * Sort resolvers by priority (descending)
     */
    private function sortResolvers(): void
    {
        if ($this->resolversSorted) {
            return;
        }

        usort(
            $this->resolvers,
            fn(VersionResolverInterface $a, VersionResolverInterface $b) =>
                $b->getPriority() <=> $a->getPriority()
        );

        $this->resolversSorted = true;
    }
}
