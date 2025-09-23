<?php

declare(strict_types=1);

namespace Glueful\Tasks\ServiceProvider;

use Glueful\Container\Definition\DefinitionInterface;
use Glueful\Container\Providers\BaseServiceProvider;
use Glueful\Tasks\{
    CacheMaintenanceTask,
    DatabaseBackupTask,
    LogCleanupTask,
    NotificationRetryTask,
    SessionCleanupTask
};

final class TasksProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // Tasks are stateless and can be autowired directly
        $defs[CacheMaintenanceTask::class] = $this->autowire(CacheMaintenanceTask::class);
        $defs[DatabaseBackupTask::class] = $this->autowire(DatabaseBackupTask::class);
        $defs[LogCleanupTask::class] = $this->autowire(LogCleanupTask::class);
        $defs[NotificationRetryTask::class] = $this->autowire(NotificationRetryTask::class);
        $defs[SessionCleanupTask::class] = $this->autowire(SessionCleanupTask::class);

        return $defs;
    }
}
