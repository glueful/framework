<?php

declare(strict_types=1);

namespace Glueful\Api\Versioning\Middleware;

use Glueful\Api\Versioning\ApiVersion;
use Glueful\Api\Versioning\VersionManager;
use Glueful\Api\Versioning\Attributes\Version;
use Glueful\Api\Versioning\Attributes\Deprecated;
use Glueful\Api\Versioning\Attributes\Sunset;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * API Version Negotiation Middleware
 *
 * Responsibilities:
 * - Negotiate API version from request using configured resolvers
 * - Validate version against route constraints
 * - Add deprecation and sunset headers (RFC 8594)
 * - Store version in request attributes for downstream use
 *
 * Response Headers Added:
 * - X-Api-Version: Current API version
 * - Deprecation: true (when version/endpoint is deprecated)
 * - Sunset: RFC 7231 date (when sunset date is set)
 * - Warning: 299 deprecation message
 * - Link: successor-version relation
 */
class VersionNegotiationMiddleware implements RouteMiddleware
{
    /** Request attribute key for ApiVersion object */
    public const REQUEST_VERSION_ATTRIBUTE = 'api_version';

    /** Request attribute key for version string */
    public const REQUEST_VERSION_STRING_ATTRIBUTE = 'api_version_string';

    private LoggerInterface $logger;

    public function __construct(
        private readonly VersionManager $versionManager,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Handle API version negotiation
     *
     * @param Request $request The HTTP request
     * @param callable $next Next handler in pipeline
     * @param mixed ...$params Optional parameters
     * @return mixed Response
     */
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // Negotiate version from request
        $version = $this->versionManager->negotiate($request);

        // Store in request attributes for downstream use
        $request->attributes->set(self::REQUEST_VERSION_ATTRIBUTE, $version);
        $request->attributes->set(self::REQUEST_VERSION_STRING_ATTRIBUTE, $version->toString());

        // Check route-level version constraints if handler metadata is available
        $routeMeta = $request->attributes->get('_route_meta', []);
        if (is_array($routeMeta) && isset($routeMeta['class'], $routeMeta['method'])) {
            $versionCheck = $this->checkRouteVersionConstraints(
                $routeMeta['class'],
                $routeMeta['method'],
                $version
            );
            if ($versionCheck !== null) {
                return $versionCheck;
            }
        }

        // Execute the next handler
        $response = $next($request);

        // Add version-related headers to response
        if ($response instanceof Response) {
            $this->addVersionHeaders($response, $version, $routeMeta);
        }

        return $response;
    }

    /**
     * Check if request version matches route constraints
     *
     * @param class-string $class Controller class
     * @param string $method Method name
     * @param ApiVersion $version Negotiated version
     * @return Response|null Error response if version doesn't match, null otherwise
     */
    private function checkRouteVersionConstraints(
        string $class,
        string $method,
        ApiVersion $version
    ): ?Response {
        $versionAttr = $this->getVersionAttribute($class, $method);

        if ($versionAttr !== null && !$versionAttr->matches($version)) {
            $this->logger->info('API version mismatch', [
                'requested' => $version->toString(),
                'constraint' => $versionAttr->getDescription(),
                'class' => $class,
                'method' => $method,
            ]);

            return new JsonResponse([
                'error' => 'Version Not Supported',
                'message' => "API version {$version->toString()} is not supported for this endpoint",
                'supported_versions' => $this->versionManager->getSupportedVersions(),
                'requested_version' => $version->toString(),
            ], Response::HTTP_NOT_FOUND);
        }

        return null;
    }

    /**
     * Add version-related headers to response
     *
     * @param Response $response The response
     * @param ApiVersion $version Negotiated version
     * @param array<string, mixed> $routeMeta Route metadata
     */
    private function addVersionHeaders(Response $response, ApiVersion $version, array $routeMeta): void
    {
        // Always add API-Version header
        $response->headers->set('X-Api-Version', $version->toString());

        $class = $routeMeta['class'] ?? null;
        $method = $routeMeta['method'] ?? null;

        // Check for deprecation (from attribute or version manager)
        $deprecatedAttr = null;
        if (is_string($class) && is_string($method)) {
            $deprecatedAttr = $this->getDeprecatedAttribute($class, $method);
        }
        $isDeprecated = $deprecatedAttr !== null || $this->versionManager->isDeprecated($version);

        if ($isDeprecated) {
            $this->addDeprecationHeaders($response, $version, $deprecatedAttr);
        }

        // Check for sunset date (from attribute or version manager)
        $sunsetAttr = null;
        if (is_string($class) && is_string($method)) {
            $sunsetAttr = $this->getSunsetAttribute($class, $method);
        }
        $sunsetDate = $sunsetAttr?->date ?? $this->versionManager->getSunsetDate($version);

        if ($sunsetDate !== null) {
            $this->addSunsetHeaders($response, $sunsetDate, $sunsetAttr);
        }
    }

    /**
     * Add deprecation-related headers
     */
    private function addDeprecationHeaders(
        Response $response,
        ApiVersion $version,
        ?Deprecated $deprecatedAttr
    ): void {
        // Deprecation header (draft standard)
        $response->headers->set('Deprecation', 'true');

        // Warning header with deprecation message
        $message = $deprecatedAttr?->getFullMessage()
            ?? $this->versionManager->getDeprecationMessage($version)
            ?? 'This API version is deprecated';
        $response->headers->set('Warning', '299 - "' . $message . '"');

        // Link to alternative if available
        $alternative = $deprecatedAttr?->alternative
            ?? $this->versionManager->getAlternativeUrl($version);

        if ($alternative !== null) {
            $this->addLinkHeader($response, $alternative, 'successor-version');
        }

        // Link to documentation if available
        if ($deprecatedAttr?->link !== null) {
            $this->addLinkHeader($response, $deprecatedAttr->link, 'deprecation');
        }
    }

    /**
     * Add sunset-related headers (RFC 8594)
     */
    private function addSunsetHeaders(
        Response $response,
        \DateTimeImmutable $sunsetDate,
        ?Sunset $sunsetAttr
    ): void {
        // RFC 8594 Sunset header
        $response->headers->set('Sunset', $sunsetDate->format(\DateTimeInterface::RFC7231));

        // Link to sunset documentation if available
        if ($sunsetAttr?->link !== null) {
            $this->addLinkHeader($response, $sunsetAttr->link, 'sunset');
        }
    }

    /**
     * Add or append to Link header
     */
    private function addLinkHeader(Response $response, string $url, string $rel): void
    {
        $newLink = '<' . $url . '>; rel="' . $rel . '"';
        $existingLink = $response->headers->get('Link', '');

        if ($existingLink !== '') {
            $response->headers->set('Link', $existingLink . ', ' . $newLink);
        } else {
            $response->headers->set('Link', $newLink);
        }
    }

    /**
     * Get Version attribute from controller/method
     */
    private function getVersionAttribute(string $class, string $method): ?Version
    {
        return $this->getAttribute($class, $method, Version::class);
    }

    /**
     * Get Deprecated attribute from controller/method
     */
    private function getDeprecatedAttribute(string $class, string $method): ?Deprecated
    {
        return $this->getAttribute($class, $method, Deprecated::class);
    }

    /**
     * Get Sunset attribute from controller/method
     */
    private function getSunsetAttribute(string $class, string $method): ?Sunset
    {
        return $this->getAttribute($class, $method, Sunset::class);
    }

    /**
     * Get attribute from controller class or method
     *
     * Checks method first, then falls back to class.
     *
     * @template T of object
     * @param class-string $class Controller class
     * @param string $method Method name
     * @param class-string<T> $attributeClass Attribute class to look for
     * @return T|null
     */
    private function getAttribute(string $class, string $method, string $attributeClass): ?object
    {
        try {
            // Check method first
            $reflection = new \ReflectionMethod($class, $method);
            $attrs = $reflection->getAttributes($attributeClass);
            if (count($attrs) > 0) {
                return $attrs[0]->newInstance();
            }

            // Fall back to class
            $classReflection = new \ReflectionClass($class);
            $classAttrs = $classReflection->getAttributes($attributeClass);
            if (count($classAttrs) > 0) {
                return $classAttrs[0]->newInstance();
            }
        } catch (\ReflectionException $e) {
            $this->logger->debug('Reflection error getting attribute', [
                'class' => $class,
                'method' => $method,
                'attribute' => $attributeClass,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
