<?php

declare(strict_types=1);

namespace Glueful\Http;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Glueful\Http\Response\Response;
use Glueful\Http\Exceptions\HttpClientException;
use Glueful\Events\Http\HttpClientFailureEvent;
use Glueful\Events\EventService;
use Psr\Log\LoggerInterface;

/**
 * HTTP Client Service
 *
 * Modern HTTP client built on Symfony HttpClient with support for async requests,
 * connection pooling, retry mechanisms, and PSR-18 compliance.
 */
class Client
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private ?ApplicationContext $context = null
    ) {
    }

    /**
     * Send a GET request
     * @param array<string, mixed> $options
     */
    public function get(string $url, array $options = []): Response
    {
        return $this->request('GET', $url, $options);
    }

    /**
     * Send a POST request
     * @param array<string, mixed> $options
     */
    public function post(string $url, array $options = []): Response
    {
        return $this->request('POST', $url, $options);
    }

    /**
     * Send a PUT request
     * @param array<string, mixed> $options
     */
    public function put(string $url, array $options = []): Response
    {
        return $this->request('PUT', $url, $options);
    }

    /**
     * Send a DELETE request
     * @param array<string, mixed> $options
     */
    public function delete(string $url, array $options = []): Response
    {
        return $this->request('DELETE', $url, $options);
    }

    /**
     * Send a PATCH request
     * @param array<string, mixed> $options
     */
    public function patch(string $url, array $options = []): Response
    {
        return $this->request('PATCH', $url, $options);
    }

    /**
     * Fetch a URL only after SSRF-oriented URL validation.
     *
     * Redirects are handled manually so each Location hop is resolved and
     * re-validated before a follow-up request is made. This method is intended
     * for user-provided URLs; ordinary first-party integration calls can keep
     * using get()/request().
     *
     * @param array<string, mixed> $options
     */
    public function safeFetch(string $url, array $options = []): Response
    {
        return $this->safeRequest('GET', $url, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function safeRequest(string $method, string $url, array $options = []): Response
    {
        $maxRedirects = (int) ($options['max_redirects'] ?? $this->getConfig('http.safe_fetch.max_redirects', 3));
        unset($options['max_redirects']);

        $currentUrl = $url;
        for ($redirects = 0;; $redirects++) {
            $resolution = $this->assertSafeFetchUrl($currentUrl);

            $symfonyOptions = $this->transformOptions($options);
            $symfonyOptions['max_redirects'] = 0;
            $symfonyOptions['resolve'][$resolution['host']] = $resolution['ip'];

            try {
                $response = $this->httpClient->request($method, $currentUrl, $symfonyOptions);
                $statusCode = $response->getStatusCode();

                if ($statusCode < 300 || $statusCode >= 400) {
                    return new Response($response);
                }

                $headers = $response->getHeaders(false);
                $location = $headers['location'][0] ?? $headers['Location'][0] ?? null;
                if (!is_string($location) || $location === '') {
                    return new Response($response);
                }

                if ($redirects >= $maxRedirects) {
                    throw new HttpClientException('Maximum safeFetch redirects exceeded', $statusCode);
                }

                $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);
            } catch (HttpClientException $e) {
                throw $e;
            } catch (\Exception $e) {
                throw new HttpClientException($e->getMessage(), $e->getCode());
            }
        }
    }

    /**
     * Send an HTTP request
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): Response
    {
        $startTime = microtime(true);

        try {
            // Transform options to Symfony format
            $symfonyOptions = $this->transformOptions($options);

            $response = $this->httpClient->request($method, $url, $symfonyOptions);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            // Log slow requests
            $this->logSlowRequest($method, $url, $duration);

            // Log server errors
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 500) {
                $this->logServerError($method, $url, $statusCode, $duration);

                $exception = new HttpClientException("HTTP $statusCode error from server", $statusCode);
                $this->dispatchEvent(new HttpClientFailureEvent(
                    $method,
                    $url,
                    $exception,
                    'server_error',
                    ['duration_ms' => $duration, 'status_code' => $statusCode]
                ));
            }

            return new Response($response);
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->error('HTTP request failed', [
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);

            $exception = new HttpClientException($e->getMessage(), $e->getCode());

            $this->dispatchEvent(new HttpClientFailureEvent(
                $method,
                $url,
                $exception,
                'connection_failed',
                ['duration_ms' => $duration]
            ));

            throw $exception;
        }
    }

    /**
     * Create a scoped client with default options
     */
    /**
     * @param array<string, mixed> $defaultOptions
     */
    public function createScopedClient(array $defaultOptions = []): self
    {
        $scopedClient = $this->httpClient->withOptions($defaultOptions);
        return new self($scopedClient, $this->logger, $this->context);
    }

    /**
     * Expose the underlying Symfony HttpClient for composition.
     */
    public function getHttpClient(): HttpClientInterface
    {
        return $this->httpClient;
    }

    /**
     * Return a new Client instance using a provided HttpClientInterface, preserving the logger.
     */
    public function withHttpClient(HttpClientInterface $httpClient): self
    {
        return new self($httpClient, $this->logger, $this->context);
    }

    /**
     * Return a new Client instance wrapped with Symfony's RetryableHttpClient.
     *
     * @param array<string, mixed> $config
     */
    public function withRetry(array $config): self
    {
        $strategy = new GenericRetryStrategy(
            statusCodes: $config['status_codes'] ?? [423, 425, 429, 500, 502, 503, 504, 507, 510],
            delayMs: $config['delay_ms'] ?? 1000,
            multiplier: $config['multiplier'] ?? 2.0,
            maxDelayMs: $config['max_delay_ms'] ?? 30000,
            jitter: $config['jitter'] ?? 0.1
        );

        $retrying = new RetryableHttpClient(
            $this->httpClient,
            $strategy,
            maxRetries: $config['max_retries'] ?? 3
        );

        return new self($retrying, $this->logger, $this->context);
    }

    private function dispatchEvent(object $event): void
    {
        if ($this->context === null) {
            return;
        }

        try {
            app($this->context, EventService::class)->dispatch($event);
        } catch (\Throwable) {
            // best-effort only
        }
    }

    /**
     * Send an async request (returns Symfony ResponseInterface)
     */
    /**
     * @param array<string, mixed> $options
     */
    public function requestAsync(string $method, string $url, array $options = []): ResponseInterface
    {
        $symfonyOptions = $this->transformOptions($options);
        return $this->httpClient->request($method, $url, $symfonyOptions);
    }

    /**
     * Send multiple requests in batch
     */
    /**
     * @param array<mixed> $requests
     * @return array<mixed>
     */
    public function requestBatch(array $requests): array
    {
        $responses = [];
        foreach ($requests as $key => $request) {
            $responses[$key] = $this->requestAsync(
                $request['method'],
                $request['url'],
                $request['options'] ?? []
            );
        }
        return $responses;
    }

    /**
     * Transform Glueful options to Symfony HttpClient format
     */
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function transformOptions(array $options): array
    {
        $symfonyOptions = [];

        // Transform timeout options
        if (isset($options['timeout'])) {
            $symfonyOptions['timeout'] = $options['timeout'];
        }
        if (isset($options['connect_timeout'])) {
            $symfonyOptions['timeout'] = $options['connect_timeout'];
        }

        // Transform headers
        if (isset($options['headers'])) {
            $symfonyOptions['headers'] = $options['headers'];
        }

        // Transform HTTP Basic authentication ([username, password] or "user:pass").
        // Symfony HttpClient supports auth_basic natively; without this passthrough
        // per-request basic auth would be silently dropped.
        if (isset($options['auth_basic'])) {
            $symfonyOptions['auth_basic'] = $options['auth_basic'];
        }

        // Transform query parameters
        if (isset($options['query'])) {
            $symfonyOptions['query'] = $options['query'];
        }

        // Transform JSON body
        if (isset($options['json'])) {
            $symfonyOptions['json'] = $options['json'];
        }

        // Transform form parameters
        if (isset($options['form_params'])) {
            $symfonyOptions['body'] = http_build_query($options['form_params']);
            $symfonyOptions['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        // Transform raw body
        if (isset($options['body'])) {
            $symfonyOptions['body'] = $options['body'];
        }

        // Transform SSL verification
        if (isset($options['verify'])) {
            $symfonyOptions['verify_peer'] = $options['verify'];
            $symfonyOptions['verify_host'] = $options['verify'];
        }

        // Transform sink (file download)
        if (isset($options['sink'])) {
            $symfonyOptions['buffer'] = false;
            $symfonyOptions['user_data'] = ['sink' => $options['sink']];
        }

        if (isset($options['max_redirects'])) {
            $symfonyOptions['max_redirects'] = $options['max_redirects'];
        }

        return $symfonyOptions;
    }

    /**
     * @return array{host: string, ip: string}
     */
    private function assertSafeFetchUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false) {
            throw new HttpClientException('Unsafe URL: invalid URL');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new HttpClientException('Unsafe URL: only http and https schemes are allowed');
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), "[] \t\n\r\0\x0B"));
        if ($host === '' || $host === 'localhost') {
            throw new HttpClientException('Unsafe URL: host is not allowed');
        }

        $ips = $this->resolveHostIps($host);
        if ($ips === []) {
            throw new HttpClientException('Unsafe URL: host could not be resolved');
        }

        foreach ($ips as $ip) {
            if (
                filter_var(
                    $ip,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                ) === false
            ) {
                throw new HttpClientException('Unsafe URL: host resolves to a private or reserved address');
            }
        }

        return [
            'host' => $host,
            'ip' => $ips[0],
        ];
    }

    /**
     * @return array<string>
     */
    private function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];
        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $ips = array_merge($ips, $ipv4);
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        $parts = parse_url($location);
        if (is_array($parts) && isset($parts['scheme'])) {
            return $location;
        }

        $base = parse_url($baseUrl);
        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            throw new HttpClientException('Unsafe URL: invalid redirect base URL');
        }

        $origin = $base['scheme'] . '://' . $base['host']
            . (isset($base['port']) ? ':' . $base['port'] : '');

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = $base['path'] ?? '/';
        $dir = preg_replace('#/[^/]*$#', '/', $path) ?? '/';

        return $origin . $dir . $location;
    }

    /**
     * Log slow HTTP requests
     */
    private function logSlowRequest(string $method, string $url, float $duration): void
    {
        $slowThreshold = (int) $this->getConfig('http.logging.slow_threshold_ms', 5000);
        if ($duration > $slowThreshold) {
            $this->logger->warning('HTTP client slow request', [
                'type' => 'performance',
                'message' => 'HTTP request exceeded threshold',
                'url' => $url,
                'method' => $method,
                'duration_ms' => $duration,
                'threshold_ms' => $slowThreshold,
                'timestamp' => date('c')
            ]);
        }
    }

    /**
     * Log server errors
     */
    private function logServerError(string $method, string $url, int $statusCode, float $duration): void
    {
        $this->logger->error('HTTP client server error', [
            'type' => 'http_client',
            'message' => 'Server error response received',
            'url' => $url,
            'method' => $method,
            'status' => $statusCode,
            'duration_ms' => $duration,
            'timestamp' => date('c')
        ]);
    }

    /**
     * Set logger for framework infrastructure logging (backward compatibility)
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    private function getConfig(string $key, mixed $default = null): mixed
    {
        if ($this->context === null) {
            return $default;
        }

        return config($this->context, $key, $default);
    }
}
