<?php

namespace Glueful\Console\Commands\Security;

use Glueful\Console\Commands\Security\BaseSecurityCommand;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Security Lockdown Command
 * - Emergency security lockdown mode activation/deactivation
 * - Lockdown status monitoring and reporting
 * - Granular lockdown controls and exceptions
 * - Automated threat response and mitigation
 * - Security incident logging and alerting
 * @package Glueful\Console\Commands\Security
 */
#[AsCommand(
    name: 'security:lockdown',
    description: 'Manage emergency security lockdown mode'
)]
class LockdownCommand extends BaseSecurityCommand
{
    private LoggerInterface $logger;
    protected function configure(): void
    {
        $this->setDescription('Manage emergency security lockdown mode')
             ->setHelp('This command manages the emergency security lockdown mode which ' .
                      'restricts access and heightens security measures.')
             ->addOption(
                 'enable',
                 'e',
                 InputOption::VALUE_NONE,
                 'Enable security lockdown mode'
             )
             ->addOption(
                 'disable',
                 'd',
                 InputOption::VALUE_NONE,
                 'Disable security lockdown mode'
             )
             ->addOption(
                 'status',
                 's',
                 InputOption::VALUE_NONE,
                 'Check current lockdown status'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Force lockdown operation without confirmation'
             )
             ->addOption(
                 'reason',
                 'r',
                 InputOption::VALUE_REQUIRED,
                 'Reason for lockdown activation/deactivation'
             )
             ->addOption(
                 'cleanup',
                 'c',
                 InputOption::VALUE_NONE,
                 'Remove all lockdown files when disabling'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initializeServices();

        $enable = (bool) $input->getOption('enable');
        $disable = (bool) $input->getOption('disable');
        $status = (bool) $input->getOption('status');
        $force = (bool) $input->getOption('force');
        $reasonOption = $input->getOption('reason');
        $reason = $reasonOption !== false && $reasonOption !== null ? (string) $reasonOption : null;
        $cleanup = (bool) $input->getOption('cleanup');

        // Validate options
        $actionCount = ($enable ? 1 : 0) + ($disable ? 1 : 0) + ($status ? 1 : 0);
        if ($actionCount === 0) {
            $status = true; // Default to status if no action specified
        } elseif ($actionCount > 1) {
            $this->error('Please specify only one action: --enable, --disable, or --status');
            return self::FAILURE;
        }

        try {
            if ($status === true) {
                return $this->handleLockdownStatus();
            } elseif ($enable === true) {
                return $this->handleLockdownEnable($force, $reason);
            } elseif ($disable === true) {
                return $this->handleLockdownDisable($force, $reason, $cleanup);
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Lockdown operation failed: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function handleLockdownStatus(): int
    {
        $this->info('🔍 Lockdown Status Check');
        $this->line('');

        $storagePath = config('app.paths.storage', './storage/');
        $maintenanceFile = $storagePath . 'framework/maintenance.json';

        if (!file_exists($maintenanceFile)) {
            $this->success('✅ System is NOT in lockdown mode');
            return self::SUCCESS;
        }

        $jsonContent = file_get_contents($maintenanceFile);
        if ($jsonContent === false) {
            $this->success('✅ System is NOT in lockdown mode');
            return self::SUCCESS;
        }
        $maintenanceData = json_decode($jsonContent, true);

        if ($maintenanceData === null || ($maintenanceData['enabled'] ?? false) !== true) {
            $this->success('✅ System is NOT in lockdown mode');
            return self::SUCCESS;
        }

        if (($maintenanceData['lockdown_mode'] ?? false) !== true) {
            $this->info('🔧 System is in maintenance mode (not security lockdown)');
            return self::SUCCESS;
        }

        // Check if lockdown has expired
        $endTime = $maintenanceData['end_time'] ?? null;
        if ($endTime !== null && time() > $endTime) {
            $this->warning('⚠️ Lockdown has EXPIRED but files still exist');
            $this->info('Run: php glueful security:lockdown --disable --cleanup');
            return self::SUCCESS;
        }

        $this->error('🚨 System is in ACTIVE LOCKDOWN mode');
        $this->line('');

        $this->displayLockdownDetails($maintenanceData);

        return self::SUCCESS;
    }

    private function handleLockdownEnable(bool $force, ?string $reason): int
    {
        $this->info('🔒 Enabling Security Lockdown');
        $this->line('');

        $storagePath = config('app.paths.storage', './storage/');
        $maintenanceFile = $storagePath . 'framework/maintenance.json';

        // Check if already enabled
        if (file_exists($maintenanceFile)) {
            $jsonContent = file_get_contents($maintenanceFile);
            if ($jsonContent === false) {
                $this->warning('Security lockdown is already disabled');
                return self::SUCCESS;
            }
            $maintenanceData = json_decode($jsonContent, true);
            if (
                $maintenanceData !== null &&
                ($maintenanceData['enabled'] ?? false) === true &&
                ($maintenanceData['lockdown_mode'] ?? false) === true
            ) {
                $this->warning('Security lockdown is already enabled');
                return self::SUCCESS;
            }
        }

        // Confirmation if not forced
        if ($force !== true) {
            $this->warning('This will enable emergency security lockdown mode.');
            $this->warning('Access to the system will be restricted.');

            if (!$this->confirm('Are you sure you want to enable lockdown mode?', false)) {
                $this->info('Lockdown activation cancelled.');
                return self::SUCCESS;
            }
        }

        // Get reason if not provided
        if ($reason === null && $force !== true) {
            $reason = $this->ask('Please provide a reason for enabling lockdown mode');
        }

        // Enable lockdown
        $lockdownData = [
            'enabled' => true,
            'lockdown_mode' => true,
            'reason' => $reason ?? 'Emergency lockdown via CLI',
            'start_time' => time(),
            'end_time' => time() + 3600, // Default 1 hour
            'enabled_by' => 'CLI',
            'enabled_at' => date('Y-m-d H:i:s')
        ];

        // Ensure directory exists
        $frameworkDir = dirname($maintenanceFile);
        if (!is_dir($frameworkDir)) {
            mkdir($frameworkDir, 0755, true);
        }

        if (file_put_contents($maintenanceFile, json_encode($lockdownData, JSON_PRETTY_PRINT)) === false) {
            $this->error('Failed to create lockdown file');
            return self::FAILURE;
        }

        $this->success('🔴 Security lockdown mode ENABLED');
        $this->line('');
        $this->warning('System access is now restricted');
        $this->info('To disable lockdown: php glueful security:lockdown --disable');

        return self::SUCCESS;
    }

    private function handleLockdownDisable(bool $force, ?string $reason, bool $cleanup): int
    {
        $this->info('🔓 Disabling Security Lockdown');
        $this->line('');

        $storagePath = config('app.paths.storage', './storage/');
        $maintenanceFile = $storagePath . 'framework/maintenance.json';

        // Check if already disabled
        if (!file_exists($maintenanceFile)) {
            $this->info('Security lockdown is already disabled');
            return self::SUCCESS;
        }

        // Confirmation if not forced
        if ($force !== true) {
            $this->info('This will disable security lockdown mode and restore normal access.');

            if (!$this->confirm('Are you sure you want to disable lockdown mode?', false)) {
                $this->info('Lockdown deactivation cancelled.');
                return self::SUCCESS;
            }
        }

        // Get reason if not provided
        if ($reason === null && $force !== true) {
            $reason = $this->ask('Please provide a reason for disabling lockdown mode');
        }

        // Remove lockdown file
        if (unlink($maintenanceFile)) {
            $this->success('🟢 Security lockdown mode DISABLED');
            $this->line('');
            $this->success('System access has been restored to normal');

            // Log the disable action
            try {
                $this->logger->info('Security lockdown disabled', [
                    'reason' => $reason ?? 'Manual deactivation via CLI',
                    'disabled_by' => 'CLI',
                    'disabled_at' => date('Y-m-d H:i:s')
                ]);
            } catch (\Exception $e) {
                // Logging failed, but don't fail the operation
            }

            return self::SUCCESS;
        } else {
            $this->error('Failed to remove lockdown file');
            return self::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $maintenanceData
     */
    private function displayLockdownDetails(array $maintenanceData): void
    {
        $this->info('📊 Lockdown Details:');
        $this->line('═══════════════════════════════════════════');

        $details = [];

        if (isset($maintenanceData['reason'])) {
            $details[] = ['Reason', $maintenanceData['reason']];
        }

        if (isset($maintenanceData['start_time'])) {
            $details[] = ['Started At', date('Y-m-d H:i:s', $maintenanceData['start_time'])];
        }

        if (isset($maintenanceData['end_time'])) {
            $endTime = $maintenanceData['end_time'];
            $remaining = $endTime - time();
            $details[] = ['Expires At', date('Y-m-d H:i:s', $endTime)];

            if ($remaining > 0) {
                $hours = floor($remaining / 3600);
                $minutes = floor(($remaining % 3600) / 60);
                $details[] = ['Time Remaining', "{$hours}h {$minutes}m"];
            }
        }

        if (isset($maintenanceData['enabled_by'])) {
            $details[] = ['Enabled By', $maintenanceData['enabled_by']];
        }

        $this->table(['Property', 'Value'], $details);

        $this->line('');
        $this->warning('🚨 To disable lockdown mode:');
        $this->info('   php glueful security:lockdown --disable');
        $this->info('   php glueful security:lockdown --disable --force  (skip confirmation)');
    }

    private function initializeServices(): void
    {
        $this->logger = $this->getService(LoggerInterface::class);
    }
}
