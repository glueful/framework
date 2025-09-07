<?php

declare(strict_types=1);

namespace Glueful\Logging;

use DateTimeImmutable;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Adds consistent structured fields to every log record.
 *
 * - environment: current app environment (production/development/testing)
 * - framework_version: resolved framework version string
 * - timestamp: ISO-8601 timestamp
 * - memory_usage / peak_memory: current and peak memory usage
 * - process_id: current process id
 * - user_id: resolved via optional provider
 */
final class StandardLogProcessor implements ProcessorInterface
{
    /** @var null|callable():(?string) */
    private $userIdResolver;

    public function __construct(
        private readonly string $environment,
        private readonly string $frameworkVersion,
        ?callable $userIdResolver = null
    ) {
        $this->userIdResolver = $userIdResolver;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra = array_merge($record->extra, [
            'environment' => $this->environment,
            'framework_version' => $this->frameworkVersion,
            'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'process_id' => getmypid(),
        ]);

        $userId = $this->resolveUserId();
        if ($userId !== null) {
            $record->extra['user_id'] = $userId;
        }

        return $record;
    }

    private function resolveUserId(): ?string
    {
        if ($this->userIdResolver !== null) {
            try {
                return ($this->userIdResolver)();
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }
}
