<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\DefinitionInterface;

final class ConsoleProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // List of command classes to register and tag
        $commands = [
            // Migration commands
            \Glueful\Console\Commands\Migrate\RunCommand::class,
            \Glueful\Console\Commands\Migrate\CreateCommand::class,
            \Glueful\Console\Commands\Migrate\StatusCommand::class,
            \Glueful\Console\Commands\Migrate\RollbackCommand::class,
            // Development commands
            \Glueful\Console\Commands\ServeCommand::class,
            \Glueful\Console\Commands\VersionCommand::class,
            // Cache commands
            \Glueful\Console\Commands\Cache\ClearCommand::class,
            \Glueful\Console\Commands\Cache\StatusCommand::class,
            \Glueful\Console\Commands\Cache\GetCommand::class,
            \Glueful\Console\Commands\Cache\SetCommand::class,
            \Glueful\Console\Commands\Cache\DeleteCommand::class,
            \Glueful\Console\Commands\Cache\TtlCommand::class,
            \Glueful\Console\Commands\Cache\ExpireCommand::class,
            \Glueful\Console\Commands\Cache\PurgeCommand::class,
            \Glueful\Console\Commands\Cache\MaintenanceCommand::class,
            // Database commands
            \Glueful\Console\Commands\Database\StatusCommand::class,
            \Glueful\Console\Commands\Database\ResetCommand::class,
            \Glueful\Console\Commands\Database\ProfileCommand::class,
            // Generate commands
            \Glueful\Console\Commands\Generate\ControllerCommand::class,
            \Glueful\Console\Commands\Generate\OpenApiDocsCommand::class,
            \Glueful\Console\Commands\Generate\KeyCommand::class,
            // Extensions commands
            \Glueful\Console\Commands\Extensions\InfoCommand::class,
            \Glueful\Console\Commands\Extensions\EnableCommand::class,
            \Glueful\Console\Commands\Extensions\DisableCommand::class,
            \Glueful\Console\Commands\Extensions\CreateCommand::class,
            \Glueful\Console\Commands\Extensions\ListCommand::class,
            \Glueful\Console\Commands\Extensions\SummaryCommand::class,
            \Glueful\Console\Commands\Extensions\CacheCommand::class,
            \Glueful\Console\Commands\Extensions\ClearCommand::class,
            \Glueful\Console\Commands\Extensions\WhyCommand::class,
            \Glueful\Console\Commands\Extensions\DiagnoseCommand::class,
            // System commands
            \Glueful\Console\Commands\InstallCommand::class,
            \Glueful\Console\Commands\System\CheckCommand::class,
            \Glueful\Console\Commands\System\ProductionCommand::class,
            \Glueful\Console\Commands\System\MemoryMonitorCommand::class,
            // Security commands
            \Glueful\Console\Commands\Security\CheckCommand::class,
            \Glueful\Console\Commands\Security\VulnerabilityCheckCommand::class,
            \Glueful\Console\Commands\Security\LockdownCommand::class,
            \Glueful\Console\Commands\Security\ResetPasswordCommand::class,
            \Glueful\Console\Commands\Security\ReportCommand::class,
            \Glueful\Console\Commands\Security\RevokeTokensCommand::class,
            \Glueful\Console\Commands\Security\ScanCommand::class,
            // Notification commands
            \Glueful\Console\Commands\Notifications\ProcessRetriesCommand::class,
            // Queue commands
            \Glueful\Console\Commands\Queue\WorkCommand::class,
            \Glueful\Console\Commands\Queue\AutoScaleCommand::class,
            \Glueful\Console\Commands\Queue\SchedulerCommand::class,
            // Archive commands
            \Glueful\Console\Commands\Archive\ManageCommand::class,
            // Container management commands
            \Glueful\Console\Commands\Container\ContainerDebugCommand::class,
            \Glueful\Console\Commands\Container\ContainerCompileCommand::class,
            \Glueful\Console\Commands\Container\ContainerValidateCommand::class,
            \Glueful\Console\Commands\Container\LazyStatusCommand::class,
            \Glueful\Console\Commands\Container\ContainerMapCommand::class,
            // Field analysis commands
            \Glueful\Console\Commands\Fields\AnalyzeCommand::class,
            \Glueful\Console\Commands\Fields\ValidateCommand::class,
            \Glueful\Console\Commands\Fields\PerformanceCommand::class,
            \Glueful\Console\Commands\Fields\WhitelistCheckCommand::class,
        ];

        foreach ($commands as $class) {
            $defs[$class] = $this->autowire($class);
            $this->tag($class, 'console.commands', 0);
        }

        return $defs;
    }
}
