<?php

namespace Glueful\Console\Commands\Event;

use Glueful\Console\BaseCommand;
use Glueful\Services\FileFinder;
use Glueful\Storage\StorageManager;
use Glueful\Storage\PathGuard;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Event Listener Create Command
 * Creates new event listener classes with proper structure:
 * - Interactive listener name validation
 * - Template-based file generation with event binding
 * - Support for event-specific listener methods
 * - Proper PSR-4 autoloading structure
 * - FileFinder and FileManager integration for safe file operations
 * @package Glueful\Console\Commands\Event
 */
#[AsCommand(
    name: 'event:listener',
    description: 'Create a new event listener class'
)]
class CreateListenerCommand extends BaseCommand
{
    private FileFinder $fileFinder;
    private StorageManager $storage;

    protected function configure(): void
    {
        $this->setDescription('Create a new event listener class')
             ->setHelp('This command generates a new event listener class with proper structure and ' .
                       'event handling methods.')
             ->addArgument(
                 'name',
                 InputArgument::REQUIRED,
                 'The name of the listener to generate (e.g., UserRegistrationListener)'
             )
             ->addOption(
                 'event',
                 'e',
                 InputOption::VALUE_OPTIONAL,
                 'The event class this listener should handle'
             )
             ->addOption(
                 'method',
                 'm',
                 InputOption::VALUE_OPTIONAL,
                 'The method name to handle the event (default: handle)'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initializeServices();

        $listenerName = $input->getArgument('name');
        $eventClass = $input->getOption('event');
        $methodName = $input->getOption('method') ?? 'handle';

        // Validate listener name format
        if (!$this->isValidListenerName($listenerName)) {
            $this->error('Invalid listener name format.');
            $this->line('');
            $this->info('Listener names should use PascalCase and end with "Listener".');
            $this->line('Examples:');
            $this->line('  • UserRegistrationListener');
            $this->line('  • SecurityAlertListener');
            $this->line('  • EmailNotificationListener');

            return self::FAILURE;
        }

        try {
            $this->info(sprintf('Creating listener: %s', $listenerName));

            $listenerInfo = $this->parseListenerName($listenerName);

            // Check if listener already exists
            if ($this->listenerExists($listenerInfo['path'])) {
                $this->error(sprintf('Listener already exists at: %s', $listenerInfo['path']));
                return self::FAILURE;
            }

            $filePath = $this->createListener($listenerInfo, $eventClass, $methodName);

            $this->success('Listener created successfully!');
            $this->line('');
            $this->info(sprintf('File: %s', $filePath));
            $this->info(sprintf('Class: %s', $listenerInfo['fullClassName']));
            $this->line('');
            $this->info('Next steps:');
            $this->line('  1. Implement your event handling logic in the ' . $methodName . '() method');
            $cls = $listenerInfo['className'];
            if ($eventClass === null || $eventClass === '') {
                $this->line(
                    "  2. Name the event it handles: add #[AsListener(YourEvent::class)] above {$methodName}()"
                );
            }
            $step = ($eventClass === null || $eventClass === '') ? 3 : 2;
            // #[AsListener] on the handler + subscribe() is a working registration; the old
            // [Listener::class, 'method'] hint was not a valid callable for an instance method.
            $this->line("  {$step}. Register it (e.g. in your app provider's boot()):");
            $this->line("     app(\$context, \\Glueful\\Events\\EventService::class)->subscribe(\\"
                . "{$listenerInfo['fullClassName']}::class);");
            $this->line("     // i.e. \$events->subscribe({$cls}::class)");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to create listener: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Initialize required services
     */
    private function initializeServices(): void
    {
        $this->fileFinder = new FileFinder();
        $this->storage = new StorageManager([
            'default' => 'local',
            'disks' => [
                'local' => ['driver' => 'local', 'root' => base_path($this->getContext()), 'visibility' => 'private'],
            ],
        ], new PathGuard());
    }

    /**
     * Validate listener name format
     */
    private function isValidListenerName(string $name): bool
    {
        // Remove .php extension if provided
        $name = str_replace('.php', '', $name);

        // Check if class name is valid PascalCase
        if (preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name) !== 1) {
            return false;
        }

        return true;
    }

    /**
     * Parse listener name and determine paths/namespaces
     */
    /**
     * @return array<string, string>
     */
    private function parseListenerName(string $listenerName): array
    {
        // Remove .php extension if provided
        $listenerName = str_replace('.php', '', $listenerName);

        // Ensure class name ends with Listener
        if (!str_ends_with($listenerName, 'Listener')) {
            $listenerName .= 'Listener';
        }

        // Build directory path using config
        $baseDir = config($this->getContext(), 'app.paths.app_listeners');
        if (!is_string($baseDir) || $baseDir === '') {
            $baseDir = base_path($this->getContext(), 'app/Events/Listeners');
        }
        $filePath = $baseDir . DIRECTORY_SEPARATOR . $listenerName . '.php';

        // Build namespace for application listeners
        $namespace = 'App\\Events\\Listeners';
        $fullClassName = $namespace . '\\' . $listenerName;

        return [
            'className' => $listenerName,
            'namespace' => $namespace,
            'directory' => $baseDir,
            'path' => $filePath,
            'fullClassName' => $fullClassName
        ];
    }

    /**
     * Check if listener already exists
     */
    private function listenerExists(string $path): bool
    {
        return file_exists($path);
    }

    /**
     * Create the listener file
     */
    /**
     * @param array<string, string> $listenerInfo
     */
    private function createListener(array $listenerInfo, ?string $eventClass, string $methodName): string
    {
        // Ensure directory exists
        if (!is_dir($listenerInfo['directory'])) {
            if (!@mkdir($listenerInfo['directory'], 0755, true) && !is_dir($listenerInfo['directory'])) {
                throw new \RuntimeException('Failed to create directory: ' . $listenerInfo['directory']);
            }
        }

        $content = $this->generateListenerContent($listenerInfo, $eventClass, $methodName);

        // Write file via local disk rooted at directory
        $disk = $this->makeDisk($listenerInfo['directory']);
        $disk->write(basename($listenerInfo['path']), $content);

        return $listenerInfo['path'];
    }

    /**
     * Generate listener class content
     */
    /**
     * @param array<string, string> $listenerInfo
     */
    private function generateListenerContent(array $listenerInfo, ?string $eventClass, string $methodName): string
    {
        $className = $listenerInfo['className'];
        $namespace = $listenerInfo['namespace'];

        $hasEvent = $eventClass !== null && $eventClass !== '';
        $eventShort = $hasEvent ? $this->getEventClassName($eventClass) : null;

        // Determine event parameter and imports
        $eventParameter = $hasEvent ? $eventShort . ' $event' : 'object $event';
        $eventImport = $hasEvent ? "use {$eventClass};\n" : '';
        $eventTypeHint = $eventShort ?? 'object';
        // subscribe() registers the method carrying #[AsListener]; without a known event the
        // attribute is left for the developer to fill in.
        $attribute = $hasEvent
            ? "    #[AsListener({$eventShort}::class)]\n"
            : "    // #[AsListener(YourEvent::class)] — name the event, then subscribe() this class\n";

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Glueful\\Events\\Attributes\\AsListener;
use Psr\\Log\\LoggerInterface;
{$eventImport}
/**
 * {$className}
 *
 * Handles events and performs business logic operations.
 *
 * Register (e.g. in your app provider's boot()):
 * app(\$context, \\Glueful\\Events\\EventService::class)->subscribe({$className}::class);
 */
class {$className}
{
    public function __construct(
        private ?LoggerInterface \$logger = null
    ) {
    }

    /**
     * Handle the event
     *
     * @param {$eventTypeHint} \$event The event instance
     */
{$attribute}    public function {$methodName}({$eventParameter}): void
    {
        // Implement your event handling logic here

        \$this->logger?->info('Event handled', [
            'listener' => static::class,
            'event' => get_class(\$event),
            'timestamp' => date('c'),
        ]);

        // TODO: Add your business logic here
    }
}

PHP;
    }

    /**
     * Extract class name from full event class name
     */
    private function getEventClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);
        return array_pop($parts);
    }

    /**
     * Create a disk instance for the given root directory
     */
    private function makeDisk(string $root): \League\Flysystem\FilesystemOperator
    {
        $cfg = [
            'default' => 'scaffold',
            'disks' => [
                'scaffold' => [
                    'driver' => 'local',
                    'root' => $root,
                    'visibility' => 'private',
                ],
            ],
        ];
        $sm = new StorageManager($cfg, new PathGuard());
        return $sm->disk('scaffold');
    }
}
