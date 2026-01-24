# Logging Consolidation Implementation Plan

**Status: COMPLETED**
**Created: 2026-01-24**

## Overview

Consolidate logging to use a single `activity_logs` table with channel-based categorization and retention policies, removing the unused `audit_logs` infrastructure.

## Current State

### What Exists
| Component | Location | Status |
|-----------|----------|--------|
| `LogManager` | `src/Logging/LogManager.php` | ✅ Full-featured PSR-3 logger |
| `DatabaseLogHandler` | `src/Logging/DatabaseLogHandler.php` | ✅ Writes to `activity_logs` |
| `DatabaseLogPruner` | `src/Logging/DatabaseLogPruner.php` | ✅ 90-day retention |
| `LogCleanupTask` | `src/Tasks/LogCleanupTask.php` | ⚠️ References both tables |
| `LogCleanupJob` | `src/Queue/Jobs/LogCleanupJob.php` | ⚠️ References both tables |
| `security.audit` config | `config/security.php` | ❌ Unused |
| `ENABLE_AUDIT` env | `.env.example` | ❌ Wrong variable name, unused |
| `audit_logs` migration | `api-skeleton/database/migrations/` | ❌ Table not used |

### Events Available for Logging
- `AuthenticationFailedEvent` - Failed logins
- `SessionCreatedEvent` / `SessionDestroyedEvent` - Login/logout
- `EntityCreatedEvent` / `EntityUpdatedEvent` - Data changes
- `AdminSecurityViolationEvent` - Permission violations
- `RateLimitExceededEvent` - Rate limiting

## Target Architecture

### Single Table with Channel-Based Categorization

```
┌─────────────────────────────────────────────────────────────┐
│                        activity_logs                              │
├─────────────────────────────────────────────────────────────┤
│  channel = 'app'      │  General logs        │  90 days     │
│  channel = 'auth'     │  Auth events         │  365 days    │
│  channel = 'security' │  Security events     │  365 days    │
│  channel = 'api'      │  API request logs    │  30 days     │
│  channel = 'debug'    │  Debug logs          │  7 days      │
└─────────────────────────────────────────────────────────────┘
```

### Channel Retention Policy

| Channel | Retention | Use Case |
|---------|-----------|----------|
| `debug` | 7 days | Development debugging |
| `api` | 30 days | API request/response logs |
| `app` | 90 days | General application logs |
| `auth` | 365 days | Login, logout, session events |
| `security` | 365 days | Permission denials, violations, suspicious activity |
| `error` | 365 days | Errors.md and exceptions |

## Implementation Tasks

### Phase 1: Remove Dead Configuration ✅

#### 1.1 Remove from `.env.example`
- [x] Remove `# ENABLE_AUDIT=false` line

#### 1.2 Remove from `config/security.php`
- [x] Remove entire `security.audit` section (lines 185-192)

### Phase 2: Update Log Cleanup ✅

#### 2.1 Update `config/logging.php`
- [x] Add channel retention configuration section:
```php
'retention' => [
    'default' => env('LOG_RETENTION_DAYS', 90),
    'channels' => [
        'debug' => env('LOG_RETENTION_DEBUG_DAYS', 7),
        'api' => env('LOG_RETENTION_API_DAYS', 30),
        'app' => env('LOG_RETENTION_APP_DAYS', 90),
        'auth' => env('LOG_RETENTION_AUTH_DAYS', 365),
        'security' => env('LOG_RETENTION_SECURITY_DAYS', 365),
        'error' => env('LOG_RETENTION_ERROR_DAYS', 365),
    ],
],
```

#### 2.2 Update `DatabaseLogPruner.php`
- [x] Add channel-aware retention logic
- [x] Read retention config per channel
- [x] Delete logs based on channel-specific retention

#### 2.3 Update `LogCleanupTask.php`
- [x] Remove `cleanAuditLogs()` method
- [x] Remove references to `audit_logs` table
- [x] Update to use channel-based cleanup

#### 2.4 Update `LogCleanupJob.php`
- [x] Remove `cleanAuditLogs()` calls
- [x] Simplify to single table cleanup

### Phase 3: Update Access Controls (Deferred)

Keeping `audit_logs` references in access control traits for potential future use.
These don't cause issues if the table doesn't exist.

#### 3.1 Update `TableAccessControlTrait.php`
- [x] Keep `'audit_logs'` (no harm if table doesn't exist)

#### 3.2 Update `QueryRestrictionsTrait.php`
- [x] Keep `'audit_logs'` restrictions (no harm if table doesn't exist)

#### 3.3 Update `FieldLevelPermissionsTrait.php`
- [x] Keep `'audit_logs'` field restrictions (no harm if table doesn't exist)

### Phase 4: API Skeleton Cleanup (Deferred)

The `audit_logs` migration can remain as an optional feature for users who want
a separate audit table in the future.

#### 4.1 Remove or Mark Optional
- [x] Keep `008_CreateAuditLogsTable.php` migration as optional

### Phase 5: Documentation ✅

#### 5.1 Update `.env.example`
- [x] Channel retention uses config defaults (env vars optional)

#### 5.2 Update CHANGELOG.md
- [ ] Document the consolidation (pending release)

## Code Changes Detail

### `config/security.php` - Remove audit section

```diff
-    // Audit and Monitoring
-    'audit' => [
-        'enabled' => env('AUDIT_ENABLED', true),
-        'log_failed_logins' => env('AUDIT_LOG_FAILED_LOGINS', true),
-        'log_permission_denials' => env('AUDIT_LOG_PERMISSION_DENIALS', true),
-        'log_suspicious_activity' => env('AUDIT_LOG_SUSPICIOUS_ACTIVITY', true),
-        'retention_days' => env('AUDIT_RETENTION_DAYS', 365),
-    ],
];
```

### `config/logging.php` - Add retention config

```php
/*
|--------------------------------------------------------------------------
| Log Retention Settings
|--------------------------------------------------------------------------
|
| Configure how long logs are retained per channel. Channels with longer
| retention (auth, security, error) are kept for compliance and auditing.
|
*/
'retention' => [
    'default' => env('LOG_RETENTION_DAYS', 90),
    'channels' => [
        'debug' => env('LOG_RETENTION_DEBUG_DAYS', 7),
        'api' => env('LOG_RETENTION_API_DAYS', 30),
        'app' => env('LOG_RETENTION_APP_DAYS', 90),
        'auth' => env('LOG_RETENTION_AUTH_DAYS', 365),
        'security' => env('LOG_RETENTION_SECURITY_DAYS', 365),
        'error' => env('LOG_RETENTION_ERROR_DAYS', 365),
    ],
],
```

### `DatabaseLogPruner.php` - Channel-aware cleanup

```php
public function pruneByChannel(): int
{
    $totalDeleted = 0;
    $retentionConfig = config('logging.retention.channels', []);
    $defaultRetention = config('logging.retention.default', 90);

    foreach ($retentionConfig as $channel => $days) {
        $cutoff = (new \DateTime())->modify("-{$days} days")->format('Y-m-d H:i:s');

        $deleted = $this->db->table($this->table)
            ->where('channel', $channel)
            ->where('created_at', '<', $cutoff)
            ->delete();

        $totalDeleted += $deleted;
    }

    // Clean any logs not matching configured channels using default retention
    $cutoff = (new \DateTime())->modify("-{$defaultRetention} days")->format('Y-m-d H:i:s');
    $configuredChannels = array_keys($retentionConfig);

    $deleted = $this->db->table($this->table)
        ->whereNotIn('channel', $configuredChannels)
        ->where('created_at', '<', $cutoff)
        ->delete();

    $totalDeleted += $deleted;

    return $totalDeleted;
}
```

### `LogCleanupTask.php` - Remove audit_logs

```diff
-    public function cleanAuditLogs(int $retentionDays): void
-    {
-        try {
-            // Clean audit_logs table if it exists
-            if ($this->connection->getSchemaBuilder()->hasTable('audit_logs')) {
-                $affected = $this->connection->table('audit_logs')
-                    ->where('created_at', '<', date('Y-m-d H:i:s', strtotime("-{$retentionDays} days")))
-                    ->delete();
-                // ... logging
-            }
-        } catch (\Exception $e) {
-            // ... error handling
-        }
-    }

     public function run(): void
     {
         $retentionDays = (int) config('logging.retention.default', 90);

         $this->cleanAppLogs($retentionDays);
-        $this->cleanAuditLogs($retentionDays);
+        // Channel-specific retention handled by DatabaseLogPruner
     }
```

## Testing Checklist

- [ ] Verify log cleanup works with channel-based retention
- [ ] Verify auth events log to 'auth' channel
- [ ] Verify security events log to 'security' channel
- [ ] Verify old logs are deleted per channel policy
- [ ] Verify no references to `audit_logs` remain in active code
- [ ] Run `composer run analyse` - no errors
- [ ] Run `composer test` - all tests pass

## Rollback Plan

If issues arise:
1. Revert config changes
2. Restore `cleanAuditLogs()` method
3. Keep `audit_logs` table for future use

## Future Considerations

1. ~~**Event Listeners**: Could add listeners that automatically log auth/security events to appropriate channels~~ ✅ **IMPLEMENTED**
2. ~~**Structured Audit Data**: Could add `action`, `actor_id`, `resource_type` columns to `activity_logs` for richer querying~~ ✅ **IMPLEMENTED**
3. **Separate Audit Table**: If compliance requires strict separation, can revisit Option B

## Activity Logging Subscriber (Implemented)

Added `ActivityLoggingSubscriber` that automatically logs auth/security events to appropriate channels.

**Subscriber Location:** `src/Events/Listeners/ActivityLoggingSubscriber.php`

**Events Handled:**

| Event | Channel | Log Level | Action |
|-------|---------|-----------|--------|
| `AuthenticationFailedEvent` | auth | warning | `auth_failed` |
| `SessionCreatedEvent` | auth | info | `login` |
| `SessionDestroyedEvent` | auth | info | `logout` / `session_expired` / `session_revoked` |
| `AdminSecurityViolationEvent` | security | warning | `security_violation` |
| `RateLimitExceededEvent` | security | warning/error | `rate_limit_exceeded` |

**Auto-Registration:** Subscriber is registered during framework bootstrap in `Framework::registerCoreEventSubscribers()`.

**Performance Optimizations:**
- Lazy-loaded via DI container (listeners only instantiated when event fires)
- Lightweight handlers with no heavy processing
- Leverages DatabaseLogHandler's auto-extraction of structured audit fields
- Uses appropriate log levels to support filtering

**Query Examples:**
```sql
-- All login attempts in last 24 hours
SELECT * FROM activity_logs
WHERE channel = 'auth' AND action IN ('login', 'auth_failed')
AND created_at > NOW() - INTERVAL 24 HOUR;

-- Security violations by user
SELECT * FROM activity_logs
WHERE channel = 'security' AND actor_id = 'abc123';

-- Rate limit violations
SELECT * FROM activity_logs
WHERE action = 'rate_limit_exceeded'
ORDER BY created_at DESC;
```

## Structured Audit Data (Implemented)

Added the following columns to `activity_logs` table for richer audit querying:

| Column | Type | Description |
|--------|------|-------------|
| `action` | VARCHAR(50) | Action type (create, update, delete, login, logout, etc.) |
| `actor_id` | VARCHAR(12) | User UUID who performed the action |
| `resource_type` | VARCHAR(100) | Type of resource (users, posts, sessions, etc.) |
| `resource_id` | VARCHAR(100) | ID of the specific resource |

**Auto-extraction from context:** The handler automatically extracts these fields from log context using common key names:
- `action`: from `action`, `event`, or `operation`
- `actor_id`: from `actor_id`, `user_id`, or `user_uuid`
- `resource_type`: from `resource_type`, `entity_type`, or `table`
- `resource_id`: from `resource_id`, `entity_id`, `id`, or `uuid`

**Example usage:**
```php
$logger->info('User updated profile', [
    'action' => 'update',
    'actor_id' => $user->uuid,
    'resource_type' => 'users',
    'resource_id' => $user->uuid,
]);
```

**Query examples:**
```sql
-- All actions by a specific user
SELECT * FROM activity_logs WHERE actor_id = 'abc123' ORDER BY created_at DESC;

-- All delete operations
SELECT * FROM activity_logs WHERE action = 'delete' ORDER BY created_at DESC;

-- All operations on a specific resource
SELECT * FROM activity_logs WHERE resource_type = 'users' AND resource_id = 'xyz789';

-- Security audit: all auth channel actions by user
SELECT * FROM activity_logs WHERE channel = 'auth' AND actor_id = 'abc123';
```

## Migration Notes

For existing deployments:
1. The `audit_logs` table can remain (no harm) or be dropped manually
2. No data migration needed - `activity_logs` continues working as-is
3. New retention policies take effect on next cleanup run
