<?php

declare(strict_types=1);

namespace Glueful\Exceptions;

use Glueful\Http\Exceptions\Handler;
use Psr\Log\LoggerInterface;

/**
 * Bootstrap Exception Handler (global safety net)
 *
 * Thin shim that registers PHP's global error/exception/shutdown handlers
 * and delegates to the DI-managed Handler instance once the container is built.
 *
 * Before the Handler is available (early bootstrap), a minimal fallback response
 * is produced. After Framework wires the Handler via setHandler(), all rendering,
 * reporting, and event dispatch flow through a single code path.
 */
class ExceptionHandler
{
    private static ?Handler $handler = null;
    private static ?LoggerInterface $logger = null;
    private static bool $testMode = false;

    /**
     * @var array<string, mixed>|null Captured response for testing
     */
    private static ?array $testResponse = null;

    /**
     * @var array<string, int> Error response rate limits by IP
     */
    private static array $errorResponseLimits = [];

    private static int $maxErrorResponsesPerMinute = 60;

    /**
     * Inject the DI-managed Handler so the global handlers delegate to it.
     */
    public static function setHandler(Handler $handler): void
    {
        self::$handler = $handler;
    }

    /**
     * Register PHP's global exception, error, and shutdown handlers.
     */
    public static function register(): void
    {
        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * Convert PHP errors to ErrorException.
     *
     * @throws \ErrorException
     */
    public static function handleError(int $severity, string $message, string $filename, int $lineno): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        throw new \ErrorException($message, 0, $severity, $filename, $lineno);
    }

    /**
     * Catch fatal errors during shutdown.
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $exception = new \ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line']
            );

            self::handleException($exception);
        }
    }

    /**
     * Handle an uncaught exception.
     *
     * Delegates to the Handler when available; uses a minimal fallback otherwise.
     */
    public static function handleException(\Throwable $exception): void
    {
        // Rate-limit the global handler path to prevent abuse
        if (!self::checkErrorRateLimit()) {
            self::minimalResponse(429, 'Too many error requests');
            return;
        }

        if (self::$handler !== null) {
            // Test mode: capture response array without output
            if (self::$testMode) {
                self::$handler->handleForTest($exception);
                self::$testResponse = self::$handler->getTestResponse();
                return;
            }

            // Normal mode: delegate fully to the Handler
            $response = self::$handler->handle($exception);
            $response->send();
            return;
        }

        // Fallback: Handler not yet available (early bootstrap)
        self::fallbackLog($exception);
        self::minimalResponse(500, 'Internal server error');
    }

    // ===== Backward-compatible static API =====

    /**
     * Log an exception via the Handler (or fallback).
     *
     * @param array<string, mixed> $context
     */
    public static function logError(\Throwable $exception, array $context = []): void
    {
        if (self::$handler !== null) {
            self::$handler->logError($exception, $context);
            return;
        }

        // Fallback when Handler is not yet wired
        self::fallbackLog($exception);
    }

    public static function setTestMode(bool $enabled): void
    {
        self::$testMode = $enabled;
        self::$testResponse = null;

        self::$handler?->setTestMode($enabled);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getTestResponse(): ?array
    {
        if (self::$handler !== null) {
            return self::$handler->getTestResponse();
        }

        return self::$testResponse;
    }

    /**
     * @deprecated Use DI to inject Handler with a logger instead.
     */
    public static function setLogger(?LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    /**
     * @deprecated No longer needed — Handler receives context via DI.
     */
    public static function setContext(mixed $context): void
    {
        // No-op: kept for backward compatibility during migration.
    }

    // ===== Internal helpers =====

    private static function checkErrorRateLimit(): bool
    {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $currentTime = time();
        $windowStart = $currentTime - 60;

        self::$errorResponseLimits = array_filter(
            self::$errorResponseLimits,
            fn($timestamp) => $timestamp > $windowStart
        );

        $ipKey = $clientIp . '_';
        $ipRequests = array_filter(
            self::$errorResponseLimits,
            fn($timestamp, $key) => str_starts_with($key, $ipKey) && $timestamp > $windowStart,
            ARRAY_FILTER_USE_BOTH
        );

        if (count($ipRequests) >= self::$maxErrorResponsesPerMinute) {
            return false;
        }

        self::$errorResponseLimits[$clientIp . '_' . $currentTime . '_' . mt_rand()] = $currentTime;
        return true;
    }

    private static function fallbackLog(\Throwable $exception): void
    {
        if (self::$logger !== null) {
            try {
                self::$logger->error($exception->getMessage(), [
                    'exception' => get_class($exception),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ]);
                return;
            } catch (\Throwable) {
                // Fall through to error_log
            }
        }

        error_log(sprintf(
            '[ExceptionHandler] %s: %s in %s:%d',
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        ));
    }

    private static function minimalResponse(int $statusCode, string $message): void
    {
        if (self::$testMode) {
            self::$testResponse = [
                'success' => false,
                'message' => $message,
                'code' => $statusCode,
            ];
            return;
        }

        if (!headers_sent()) {
            header('Content-Type: application/json', true, $statusCode);
        }

        echo json_encode([
            'success' => false,
            'message' => $message,
            'code' => $statusCode,
        ], JSON_THROW_ON_ERROR);
    }
}
