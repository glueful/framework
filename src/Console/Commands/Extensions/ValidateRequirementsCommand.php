<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\Commands\Extensions\BaseExtensionCommand;
use Glueful\Extensions\ExtensionRequirements;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'extensions:validate-requirements',
    description: 'Validate extension requirements and feature dependencies'
)]
class ValidateRequirementsCommand extends BaseExtensionCommand
{
    protected function configure(): void
    {
        $this->setDescription('Validate extension requirements and feature dependencies')
             ->setHelp(
                 'This command validates that required extensions are available ' .
                 'for enabled features and checks extension dependencies.'
             )
             ->addOption(
                 'features',
                 'f',
                 InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                 'Specific features to validate (e.g., --features=auth,admin)'
             )
             ->addOption(
                 'strict',
                 's',
                 InputOption::VALUE_NONE,
                 'Use strict validation mode (fail on missing extensions)'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io->title('Extension Requirements Validation');

        // Get command options
        $features = $input->getOption('features') ?? [];
        $strict = $input->getOption('strict');

        try {
            // Load extension configuration
            $config = $this->getExtensionConfig();
            if ((bool)$strict) {
                $config['installation_mode'] = 'strict';
            }

            $requirements = new ExtensionRequirements($config);

            // Validate specific features or all enabled features
            if (count($features) > 0) {
                $this->io->section('Validating specified features: ' . implode(', ', $features));
                $requirements->validateFeatureRequirements($features);
            } else {
                // Get enabled features from framework configuration
                $enabledFeatures = $this->getEnabledFeatures();
                if (count($enabledFeatures) > 0) {
                    $this->io->section('Validating enabled features: ' . implode(', ', $enabledFeatures));
                    $requirements->validateFeatureRequirements($enabledFeatures);
                }
            }

            $this->displayRequirements($requirements);
            $this->io->success('All extension requirements satisfied');

            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            $this->io->error("Extension requirements validation failed: {$e->getMessage()}");
            return self::FAILURE;
        } catch (\Exception $e) {
            $this->io->error("Unexpected error: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getExtensionConfig(): array
    {
        $configPath = base_path('config/extensions.php');

        if (!file_exists($configPath)) {
            $this->io->note('No extension configuration found, using defaults');
            return [];
        }

        return require $configPath;
    }

    /**
     * @return array<int, string>
     */
    private function getEnabledFeatures(): array
    {
        // Get enabled features from app configuration
        $features = [];

        // Check common feature flags
        if ((bool)config('auth.enabled', true)) {
            $features[] = 'auth';
        }
        if ((bool)config('notifications.enabled', false)) {
            $features[] = 'notifications';
        }
        if ((bool)config('admin.enabled', false)) {
            $features[] = 'admin';
        }

        return $features;
    }

    private function displayRequirements(ExtensionRequirements $requirements): void
    {
        $requiredExtensions = $requirements->getRequiredExtensions();
        $featureMappings = $requirements->getFeatureMappings();

        if (count($requiredExtensions) === 0 && count($featureMappings) === 0) {
            $this->io->note('No extension requirements configured');
            return;
        }

        // Display feature mappings
        if (count($featureMappings) > 0) {
            $this->io->section('Feature Extension Mappings');
            $mappingTable = $this->io->createTable();
            $mappingTable->setHeaders(['Feature', 'Required Extension', 'Status']);

            foreach ($featureMappings as $feature => $extension) {
                $status = $this->isExtensionAvailable($extension) ?
                         '<info>✅ Available</info>' :
                         '<error>❌ Missing</error>';

                $mappingTable->addRow([$feature, $extension, $status]);
            }
            $mappingTable->render();
        }

        // Display required extensions
        if (count($requiredExtensions) > 0) {
            $this->io->section('Required Extensions');
            $extensionTable = $this->io->createTable();
            $extensionTable->setHeaders(['Extension', 'Status', 'Installation Command']);

            foreach ($requiredExtensions as $extension) {
                $status = $this->isExtensionAvailable($extension) ?
                         '<info>✅ Available</info>' :
                         '<error>❌ Missing</error>';

                $installCmd = "composer require {$extension}";
                $extensionTable->addRow([$extension, $status, $installCmd]);
            }
            $extensionTable->render();
        }
    }

    private function isExtensionAvailable(string $extension): bool
    {
        if (!class_exists('Composer\\InstalledVersions')) {
            return false;
        }

        try {
            return \Composer\InstalledVersions::isInstalled($extension);
        } catch (\Exception) {
            return false;
        }
    }
}
