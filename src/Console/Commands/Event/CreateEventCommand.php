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
 * Event Create Command
 * Creates new event classes with proper structure and documentation:
 * - Interactive event name validation
 * - Template-based file generation with namespace support
 * - Support for event categories/subdirectories
 * - Proper PSR-4 autoloading structure
 * - FileFinder and FileManager integration for safe file operations
 * @package Glueful\Console\Commands\Event
 */
#[AsCommand(
    name: 'event:create',
    description: 'Create a new event class'
)]
class CreateEventCommand extends BaseCommand
{
    private FileFinder $fileFinder;
    private StorageManager $storage;

    protected function configure(): void
    {
        $this->setDescription('Create a new event class')
             ->setHelp('This command generates a new event class with proper structure and documentation.')
             ->addArgument(
                 'name',
                 InputArgument::REQUIRED,
                 'The name of the event to generate (e.g., UserRegisteredEvent or Auth/LoginFailedEvent)'
             )
             ->addOption(
                 'type',
                 't',
                 InputOption::VALUE_OPTIONAL,
                 'Event category (auth, security, database, etc.)'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initializeServices();

        $eventName = $input->getArgument('name');
        $type = $input->getOption('type');

        // Validate event name format
        if (!$this->isValidEventName($eventName)) {
            $this->error('Invalid event name format.');
            $this->line('');
            $this->info('Event names should use PascalCase and end with "Event".');
            $this->line('Examples:');
            $this->line('  • UserRegisteredEvent');
            $this->line('  • Auth/LoginFailedEvent');
            $this->line('  • Security/SecurityAlertEvent');

            return self::FAILURE;
        }

        try {
            $this->info(sprintf('Creating event: %s', $eventName));

            $eventInfo = $this->parseEventName($eventName, $type);

            // Check if event already exists
            if ($this->eventExists($eventInfo['path'])) {
                $this->error(sprintf('Event already exists at: %s', $eventInfo['path']));
                return self::FAILURE;
            }

            $filePath = $this->createEvent($eventInfo);

            $this->success('Event created successfully!');
            $this->line('');
            $this->info(sprintf('File: %s', $filePath));
            $this->info(sprintf('Class: %s\\%s', $eventInfo['namespace'], $eventInfo['className']));
            $this->line('');
            $this->info('Next steps:');
            $this->line('  1. Add properties and constructor parameters as needed');
            $this->line('  2. Create listeners with: php glueful event:listener <ListenerName>');
            $this->line('  3. Dispatch the event: ' . $eventInfo['className'] . '::dispatch($data)');
            $this->line('  4. Optional: Replace EventHelpers with specific traits if needed');
            $this->line('     - InteractsWithQueue for async processing');
            $this->line('     - Or mix individual traits: Dispatchable, Timestampable, Serializable');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to create event: ' . $e->getMessage());
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
                'local' => ['driver' => 'local', 'root' => base_path(), 'visibility' => 'private'],
            ],
        ], new PathGuard());
    }

    /**
     * Validate event name format
     */
    private function isValidEventName(string $name): bool
    {
        // Remove .php extension if provided
        $name = str_replace('.php', '', $name);

        // Split by slash for nested events
        $parts = explode('/', $name);
        $className = array_pop($parts);

        // Check if class name is valid PascalCase
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $className)) {
            return false;
        }

        // Check if all directory parts are valid
        foreach ($parts as $part) {
            if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parse event name and determine paths/namespaces
     */
    /**
     * @return array<string, string>
     */
    private function parseEventName(string $eventName, ?string $type): array
    {
        // Remove .php extension if provided
        $eventName = str_replace('.php', '', $eventName);

        // Handle type option
        if ($type !== null && $type !== '') {
            $eventName = ucfirst($type) . '/' . $eventName;
        }

        // Split by slash to handle subdirectories
        $parts = explode('/', $eventName);
        $className = array_pop($parts);

        // Ensure class name ends with Event
        if (!str_ends_with($className, 'Event')) {
            $className .= 'Event';
        }

        // Build directory path using config
        $baseDir = config('app.paths.app_events');
        $subDir = (count($parts) > 0) ? DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts) : '';
        $directory = $baseDir . $subDir;
        $filePath = $directory . DIRECTORY_SEPARATOR . $className . '.php';

        // Build namespace for application events
        $namespace = 'App\\Events' . ((count($parts) > 0) ? '\\' . implode('\\', $parts) : '');

        return [
            'className' => $className,
            'namespace' => $namespace,
            'directory' => $directory,
            'path' => $filePath,
            'subDir' => $subDir
        ];
    }

    /**
     * Check if event already exists
     */
    private function eventExists(string $path): bool
    {
        return file_exists($path);
    }

    /**
     * Create the event file
     */
    /**
     * @param array<string, string> $eventInfo
     */
    private function createEvent(array $eventInfo): string
    {
        // Ensure target directory exists
        if (!is_dir($eventInfo['directory'])) {
            if (!@mkdir($eventInfo['directory'], 0755, true) && !is_dir($eventInfo['directory'])) {
                throw new \RuntimeException('Failed to create directory: ' . $eventInfo['directory']);
            }
        }

        $content = $this->generateEventContent($eventInfo);

        // Write file via local disk rooted at directory
        $disk = $this->makeDisk($eventInfo['directory']);
        $disk->write(basename($eventInfo['path']), $content);

        return $eventInfo['path'];
    }

    /**
     * Generate event class content
     */
    /**
     * @param array<string, string> $eventInfo
     */
    private function generateEventContent(array $eventInfo): string
    {
        $className = $eventInfo['className'];
        $namespace = $eventInfo['namespace'];

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Glueful\Events\Traits\EventHelpers;

/**
 * {$className}
 *
 * Dispatched when [describe when this event occurs]
 *
 * Usage:
 * {$className}::dispatch(\$data);
 * // or
 * Event::dispatch(new {$className}(\$data));
 */
class {$className}
{
    use EventHelpers;

    public function __construct(
        // Add your event properties here
        // Example: public readonly string \$userId,
        // Example: public readonly array \$data
    ) {}
}
PHP;
    }

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
