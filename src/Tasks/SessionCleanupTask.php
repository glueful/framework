<?php

namespace Glueful\Tasks;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;

// @schedule:  0 0 * * *
// This job runs Daily at midnight

class SessionCleanupTask
{
    /** @var array{expired_access: int, expired_refresh: int, old_revoked: int, old_refresh_rows: int, errors: string[]} */
    private array $stats = [
        'expired_access' => 0,
        'expired_refresh' => 0,
        'old_revoked' => 0,
        'old_refresh_rows' => 0,
        'errors' => []
    ];

    private static Connection $connection;
    private ?ApplicationContext $context;

    public function __construct(?ApplicationContext $context = null)
    {
        $this->context = $context;
        self::$connection = Connection::fromContext($this->context);
    }

    public function cleanExpiredAccessTokens(): void
    {
        try {
            $affected = self::$connection->table('auth_sessions')
                ->where('status', 'active')
                ->where('expires_at', '<', date('Y-m-d H:i:s'))
                ->update(['status' => 'expired', 'updated_at' => date('Y-m-d H:i:s')]);

            $this->stats['expired_access'] = $affected;
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to clean expired access tokens: " . $e->getMessage();
        }
    }

    public function cleanExpiredRefreshTokens(): void
    {
        try {
            $affected = self::$connection->table('auth_refresh_tokens')
                ->where('status', 'active')
                ->where('expires_at', '<', date('Y-m-d H:i:s'))
                ->update(['status' => 'expired']);

            $this->stats['expired_refresh'] = $affected;
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to clean expired refresh tokens: " . $e->getMessage();
        }
    }

    public function cleanOldRefreshTokenRows(): void
    {
        try {
            $retentionDays = (int) $this->getConfig('session.cleanup.refresh_token_retention_days', 30);
            $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

            $affected = self::$connection->table('auth_refresh_tokens')
                ->whereIn('status', ['consumed', 'revoked', 'expired'])
                ->where('created_at', '<', $cutoffDate)
                ->delete();

            $this->stats['old_refresh_rows'] = $affected;
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to clean old refresh token rows: " . $e->getMessage();
        }
    }

    public function cleanOldRevokedSessions(): void
    {
        try {
            // Get configurable retention period, default to 30 days
            $retentionDays = $this->getConfig('session.cleanup.revoked_retention_days', 30);
            $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
            $affected = self::$connection->table('auth_sessions')
                ->where('status', 'revoked')
                ->where('updated_at', '<', $cutoffDate)
                ->delete();

            $this->stats['old_revoked'] = $affected;
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to clean old revoked sessions: " . $e->getMessage();
        }
    }

    public function logResults(): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $message = sprintf(
            "[%s] Session cleanup completed:\n" .
            "- Expired access tokens removed: %d\n" .
            "- Expired refresh tokens removed: %d\n" .
            "- Old revoked sessions removed: %d\n" .
            "- Old refresh token rows removed: %d\n",
            $timestamp,
            $this->stats['expired_access'],
            $this->stats['expired_refresh'],
            $this->stats['old_revoked'],
            $this->stats['old_refresh_rows']
        );

        if (count($this->stats['errors']) > 0) {
            $message .= "Errors:\n- " . implode("\n- ", $this->stats['errors']) . "\n";
        }

        $logFile = rtrim((string) $this->getConfig('app.paths.logs', './storage/logs/'), '/') . '/session-cleanup.log';
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents($logFile, $message . "\n", FILE_APPEND);
    }

    public function run(): void
    {
        $this->cleanExpiredAccessTokens();
        $this->cleanExpiredRefreshTokens();
        $this->cleanOldRevokedSessions();
        $this->cleanOldRefreshTokenRows();
        $this->logResults();
    }

    private function getConfig(string $key, mixed $default = null): mixed
    {
        if ($this->context === null) {
            return $default;
        }

        return config($this->context, $key, $default);
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array{expired_access: int, expired_refresh: int,
     *     old_revoked: int, old_refresh_rows: int, errors: string[]}
     */
    public function handle(array $parameters = []): array
    {
        $this->run();
        return $this->stats;
    }
}

// chmod +x /Users/michaeltawiahsowah/Sites/localhost/glueful/cron/clean-sessions.php

# Run every hour
// 0 * * * * /usr/bin/php /Users/michaeltawiahsowah/Sites/localhost/glueful/cron/clean-sessions.php

# OR run every 6 hours
// 0 */6 * * * /usr/bin/php /Users/michaeltawiahsowah/Sites/localhost/glueful/cron/clean-sessions.php

# OR run once daily at midnight
// 0 0 * * * /usr/bin/php /Users/michaeltawiahsowah/Sites/localhost/glueful/cron/clean-sessions.php
