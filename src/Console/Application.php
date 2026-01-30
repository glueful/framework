<?php

namespace Glueful\Console;

use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\Command;
use Psr\Container\ContainerInterface;
use Glueful\Support\Version;

/**
 * Glueful Symfony Console Application
 *
 * Enhanced console application built on Symfony Console:
 * - Integrates with Glueful's DI container
 * - Auto-registers commands via container tagging
 * - Provides consistent branding and help system
 * - Organized command structure by functional groups
 * - Supports modern Symfony Console patterns
 *
 * Commands are registered in ConsoleProvider using the 'console.commands' tag.
 *
 * @package Glueful\Console
 */
class Application extends BaseApplication
{
    /** @var ContainerInterface DI Container */
    protected ContainerInterface $container;

    /**
     * Initialize Glueful Console Application
     *
     * Sets up Symfony Console with Glueful integration:
     * - Configures application name and version
     * - Integrates DI container
     * - Registers available commands
     * - Sets up enhanced help system
     *
     * @param ContainerInterface $container DI Container instance
     * @param string $version Application version
     */
    public function __construct(ContainerInterface $container, ?string $version = null)
    {
        parent::__construct('Glueful CLI', $version ?? Version::getVersion());

        $this->container = $container;
        $this->registerCommands();
        $this->configureApplication();
    }

    /**
     * Register All Commands
     *
     * Registers all console commands from the container's 'console.commands' tag:
     * - Commands are tagged in ConsoleProvider
     * - Resolved via DI container with dependencies
     * - Sorted by priority (higher priority first)
     *
     * @return void
     */
    private function registerCommands(): void
    {
        // Get all commands tagged with 'console.commands' from the container
        // The TaggedIteratorDefinition resolves to an array of Command instances
        if ($this->container->has('console.commands')) {
            /** @var array<Command> $commands */
            $commands = $this->container->get('console.commands');
            foreach ($commands as $command) {
                if ($command instanceof Command) {
                    $this->addCommand($command);
                }
            }
        }
    }

    /**
     * Configure Application Settings
     *
     * Customizes Symfony Console for Glueful:
     * - Sets up custom help formatter
     * - Configures error handling
     * - Adds Glueful-specific features
     * - Sets console styling
     *
     * @return void
     */
    private function configureApplication(): void
    {
        // Set catch exceptions to true for better error handling
        $this->setCatchExceptions(true);

        // Configure auto-exit behavior
        $this->setAutoExit(true);

        // Set default command to list available commands
        $this->setDefaultCommand('list');
    }

    /**
     * Get DI Container
     *
     * Provides access to the DI container for commands:
     * - Allows service resolution
     * - Enables dependency injection
     * - Maintains container lifecycle
     *
     * @return ContainerInterface
     */
    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * Register Command Class
     *
     * Dynamically registers a command by class name:
     * - Resolves via DI container
     * - Adds to Symfony Console registry
     *
     * Note: For framework commands, prefer registering in ConsoleProvider.
     * This method is for runtime/extension command registration.
     *
     * @param string $commandClass Command class name
     * @return void
     */
    public function registerCommandClass(string $commandClass): void
    {
        // Check if command is already registered by name
        $command = $this->container->get($commandClass);
        if ($command instanceof Command) {
            $name = $command->getName();
            if ($name !== null && !$this->has($name)) {
                $this->addCommand($command);
            }
        }
    }
}
