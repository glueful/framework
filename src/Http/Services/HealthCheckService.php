<?php

declare(strict_types=1);

namespace Glueful\Http\Services;

use Glueful\Http\Client;
use Psr\Log\LoggerInterface;

/**
 * Health Check Service
 *
 * Service for monitoring external service health and availability.
 * Provides health checks, uptime monitoring, and service status tracking.
 */
class HealthCheckService
{
    /** @var array<string, array{service: string, endpoint: string, status: string, response_time_ms: float, timestamp: string, details: array<string, mixed>, status_code?: int}> */
    private array $serviceStatuses = [];

    public function __construct(
        private Client $httpClient,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Check health of a single service
     * @param array<string, mixed> $options
     * @return array{
     *     service: string,
     *     endpoint: string,
     *     status: string,
     *     response_time_ms: float,
     *     timestamp: string,
     *     details: array<string, mixed>,
     *     status_code?: int
     * }
     */
    public function checkServiceHealth(
        string $serviceName,
        string $healthEndpoint,
        array $options = []
    ): array {
        $startTime = microtime(true);
        $result = [
            'service' => $serviceName,
            'endpoint' => $healthEndpoint,
            'status' => 'unknown',
            'response_time_ms' => 0,
            'timestamp' => date('c'),
            'details' => []
        ];

        try {
            $client = $this->httpClient->createScopedClient([
                'timeout' => $options['timeout'] ?? 5,
                'max_redirects' => 0,
                'headers' => [
                    'User-Agent' => 'Glueful-HealthCheck/1.0',
                    'Accept' => '*/*'
                ]
            ]);

            $response = $client->get($healthEndpoint);
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            $result['response_time_ms'] = $responseTime;
            $result['status_code'] = $response->getStatusCode();

            if ($response->isSuccessful()) {
                $result['status'] = 'healthy';

                // Try to parse response for additional health info
                try {
                    $body = $response->getContent();
                    if ($body !== '') {
                        $healthData = json_decode($body, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $result['details'] = $healthData;
                        }
                    }
                } catch (\Exception $e) {
                    // Response body parsing failed, but service is still healthy
                }

                $this->logger->debug('Service health check passed', [
                    'service' => $serviceName,
                    'response_time_ms' => $responseTime
                ]);
            } else {
                $result['status'] = 'unhealthy';
                $result['details']['error'] = "HTTP {$response->getStatusCode()} error";

                $this->logger->warning('Service health check failed', [
                    'service' => $serviceName,
                    'status_code' => $response->getStatusCode(),
                    'response_time_ms' => $responseTime
                ]);
            }
        } catch (\Exception $e) {
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            $result['response_time_ms'] = $responseTime;
            $result['status'] = 'unhealthy';
            $result['details']['error'] = $e->getMessage();

            $this->logger->error('Service health check failed with exception', [
                'service' => $serviceName,
                'error' => $e->getMessage(),
                'response_time_ms' => $responseTime
            ]);
        }

        // Store status for tracking
        $this->serviceStatuses[$serviceName] = $result;

        return $result;
    }

    /**
     * Check health of multiple services
     * @param array<string, string|array{endpoint?: string, timeout?: int, expected_status?: int}> $services
     * @return array<string, array{
     *     service: string,
     *     endpoint: string,
     *     status: string,
     *     response_time_ms: float,
     *     timestamp: string,
     *     details: array<string, mixed>,
     *     status_code?: int
     * }>
     */
    public function checkMultipleServices(array $services): array
    {
        $results = [];

        foreach ($services as $serviceName => $config) {
            $endpoint = $config['endpoint'] ?? $config;
            $options = is_array($config) ? $config : [];

            $results[$serviceName] = $this->checkServiceHealth(
                $serviceName,
                $endpoint,
                $options
            );
        }

        return $results;
    }

    /**
     * Get overall system health status
     * @param array<string, string|array{endpoint?: string, timeout?: int, expected_status?: int}> $services
     * @return array{
     *     overall_status: string,
     *     timestamp: string,
     *     summary: array{
     *         total_services: int,
     *         healthy: int,
     *         unhealthy: int,
     *         unknown: int,
     *         average_response_time_ms: float
     *     },
     *     services: array<string, mixed>
     * }
     */
    public function getSystemHealth(array $services = []): array
    {
        if ($services !== []) {
            $results = $this->checkMultipleServices($services);
        } else {
            $results = $this->serviceStatuses;
        }

        $healthyCounts = [
            'healthy' => 0,
            'unhealthy' => 0,
            'unknown' => 0
        ];

        $totalResponseTime = 0;
        $serviceCount = count($results);

        foreach ($results as $result) {
            $status = $result['status'] ?? 'unknown';
            if (isset($healthyCounts[$status])) {
                $healthyCounts[$status]++;
            }

            $totalResponseTime += $result['response_time_ms'] ?? 0;
        }

        $overallStatus = 'healthy';
        if ($healthyCounts['unhealthy'] > 0) {
            $overallStatus = $healthyCounts['unhealthy'] >= $healthyCounts['healthy']
                ? 'unhealthy'
                : 'degraded';
        }

        return [
            'overall_status' => $overallStatus,
            'timestamp' => date('c'),
            'summary' => [
                'total_services' => $serviceCount,
                'healthy' => $healthyCounts['healthy'],
                'unhealthy' => $healthyCounts['unhealthy'],
                'unknown' => $healthyCounts['unknown'],
                'average_response_time_ms' => $serviceCount > 0
                    ? round($totalResponseTime / $serviceCount, 2)
                    : 0
            ],
            'services' => $results
        ];
    }

    /**
     * Check if a service is currently healthy
     */
    public function isServiceHealthy(string $serviceName): bool
    {
        return isset($this->serviceStatuses[$serviceName])
            && $this->serviceStatuses[$serviceName]['status'] === 'healthy';
    }

    /**
     * Get service uptime percentage
     */
    public function getServiceUptime(string $serviceName, int $hoursBack = 24): float
    {
        // This is a simplified implementation
        // In a real system, you'd store historical data

        if (!isset($this->serviceStatuses[$serviceName])) {
            return 0.0;
        }

        $status = $this->serviceStatuses[$serviceName]['status'];
        return $status === 'healthy' ? 100.0 : 0.0;
    }

    /**
     * Get service status history
     * @return array{service: string, current_status: array<string, mixed>, uptime_24h: float, uptime_7d: float}
     */
    public function getServiceHistory(string $serviceName): array
    {
        // Simplified implementation - returns current status
        // In production, you'd query stored historical data

        return [
            'service' => $serviceName,
            'current_status' => $this->serviceStatuses[$serviceName] ?? ['status' => 'unknown'],
            'uptime_24h' => $this->getServiceUptime($serviceName, 24),
            'uptime_7d' => $this->getServiceUptime($serviceName, 24 * 7)
        ];
    }

    /**
     * Clear stored statuses
     */
    public function clearStatuses(): void
    {
        $this->serviceStatuses = [];
    }

    /**
     * Get all stored service statuses
     * @return array<string, array{
     *     service: string,
     *     endpoint: string,
     *     status: string,
     *     response_time_ms: float,
     *     timestamp: string,
     *     details: array<string, mixed>,
     *     status_code?: int
     * }>
     */
    public function getAllStatuses(): array
    {
        return $this->serviceStatuses;
    }

    /**
     * Create health check configuration for common services
     * @param array<string, string> $services
     * @return array<string, array{endpoint: string, timeout: int, expected_status: int}>
     */
    public static function createServiceConfig(array $services): array
    {
        $config = [];

        foreach ($services as $name => $url) {
            $config[$name] = [
                'endpoint' => $url,
                'timeout' => 5,
                'expected_status' => 200
            ];
        }

        return $config;
    }
}
