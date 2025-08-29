<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Config\ConfigurableService;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ExtensionRequirements extends ConfigurableService
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required_extensions' => [],
            'feature_mappings' => [
                'auth' => 'glueful/rbac-extension',
                'notifications' => 'glueful/email-notification-extension',
                'admin' => 'glueful/admin-extension'
            ],
            'installation_mode' => 'strict', // strict, lenient, suggest
            'auto_install' => false,
        ]);

        $resolver->setAllowedValues('installation_mode', ['strict', 'lenient', 'suggest']);
        $resolver->setAllowedTypes('required_extensions', 'array');
        $resolver->setAllowedTypes('feature_mappings', 'array');

        // Validate required extensions exist
        $resolver->setNormalizer('required_extensions', function ($options, $value) {
            foreach ($value as $extension) {
                if (!$this->isExtensionAvailable($extension)) {
                    $this->handleMissingExtension($extension, $options['installation_mode']);
                }
            }
            return $value;
        });
    }

    private function isExtensionAvailable(string $extension): bool
    {
        // Check if extension is installed via Composer
        if (class_exists('Composer\InstalledVersions')) {
            try {
                \Composer\InstalledVersions::getVersion($extension);
                return true;
            } catch (\OutOfBoundsException) {
                // Extension not installed via Composer
            }
        }

        // Check if extension exists locally (fallback)
        $localPath = dirname(__DIR__, 3) . "/extensions/" . basename($extension);
        return is_dir($localPath);
    }

    private function handleMissingExtension(string $extension, string $mode): void
    {
        $message = "Extension '{$extension}' is required but not installed.";
        $install = "Install with: composer require {$extension}";

        match ($mode) {
            'strict' => throw new \RuntimeException("{$message} {$install}"),
            'lenient' => error_log("WARNING: {$message} {$install}"),
            'suggest' => print("SUGGESTION: {$message} {$install}\n")
        };
    }

    /**
     * Validate extension requirements based on enabled features
     */
    public function validateFeatureRequirements(array $enabledFeatures): void
    {
        $mappings = $this->getOption('feature_mappings');
        $requiredExtensions = $this->getOption('required_extensions', []);

        // Add extensions required by enabled features
        foreach ($enabledFeatures as $feature) {
            if (isset($mappings[$feature])) {
                $requiredExtensions[] = $mappings[$feature];
            }
        }

        // Remove duplicates and validate each extension
        $requiredExtensions = array_unique($requiredExtensions);
        foreach ($requiredExtensions as $extension) {
            if (!$this->isExtensionAvailable($extension)) {
                $this->handleMissingExtension($extension, $this->getOption('installation_mode', 'strict'));
            }
        }
    }

    /**
     * Get required extensions list
     *
     * @return array List of required extension package names
     */
    public function getRequiredExtensions(): array
    {
        return $this->getOption('required_extensions', []);
    }

    /**
     * Get feature to extension mappings
     *
     * @return array Mapping of features to required extensions
     */
    public function getFeatureMappings(): array
    {
        return $this->getOption('feature_mappings', []);
    }

    /**
     * Get installation mode
     *
     * @return string Installation mode (strict|lenient|suggest)
     */
    public function getInstallationMode(): string
    {
        return $this->getOption('installation_mode', 'strict');
    }
}
