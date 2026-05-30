<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\BaseCommand;
use Glueful\Support\Version;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Create Extension Command
 *
 * Scaffolds a new extension as a proper Composer package under extensions/<slug>/
 * (type: glueful-extension, extra.glueful.provider, PSR-4 autoload) and registers a
 * Composer path repository for it in the app's composer.json. It does NOT run
 * Composer — it prints the `composer require` + `extensions:enable` commands to run.
 */
#[AsCommand(
    name: 'create:extension',
    description: 'Create new local extension'
)]
final class CreateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setDescription('Scaffold a new extension as a Composer package + path repository')
            ->addArgument('name', InputArgument::REQUIRED, 'Extension name (e.g., blog, shop, widgets)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $name));
        if ($slug === '') {
            $output->writeln('<error>Invalid extension name.</error>');
            return self::FAILURE;
        }
        $studly = $this->studly($name);
        $namespace = "Glueful\\Extensions\\{$studly}";
        $provider = "{$namespace}\\{$studly}ServiceProvider";

        $extensionsDir = base_path($this->getContext(), 'extensions');
        $extensionDir = $extensionsDir . '/' . $slug;

        if (is_dir($extensionDir)) {
            $output->writeln("<error>Extension '{$slug}' already exists!</error>");
            return self::FAILURE;
        }

        // Directory structure
        @mkdir($extensionDir . '/src', 0755, true);
        @mkdir($extensionDir . '/routes', 0755, true);
        @mkdir($extensionDir . '/config', 0755, true);
        @mkdir($extensionDir . '/database/migrations', 0755, true);

        // composer.json (a real glueful-extension package)
        $composerJson = [
            'name' => "glueful/{$slug}",
            'description' => "{$name} extension for Glueful",
            'type' => 'glueful-extension',
            'require' => [
                'glueful/framework' => '>=' . Version::VERSION,
            ],
            'autoload' => [
                'psr-4' => [
                    "{$namespace}\\" => 'src/',
                ],
            ],
            'extra' => [
                'glueful' => [
                    'provider' => $provider,
                    'requires' => [
                        'glueful' => '>=' . Version::VERSION,
                        'extensions' => [],
                    ],
                ],
            ],
        ];
        file_put_contents(
            $extensionDir . '/composer.json',
            (string) json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        file_put_contents(
            $extensionDir . "/src/{$studly}ServiceProvider.php",
            $this->generateServiceProvider($namespace, $studly, $name)
        );
        file_put_contents($extensionDir . '/routes/routes.php', $this->generateRoutes($studly, $slug));
        file_put_contents($extensionDir . "/config/{$slug}.php", $this->generateConfig($slug));

        // Register a Composer path repository in the app's composer.json.
        $repoAdded = $this->registerPathRepository($slug, $output);

        $output->writeln("<info>Extension '{$slug}' scaffolded at {$extensionDir}</info>");
        if ($repoAdded) {
            $output->writeln("<info>Added path repository 'extensions/{$slug}' to composer.json.</info>");
        }
        $output->writeln('');
        $output->writeln('<comment>Next steps (this command does NOT run Composer):</comment>');
        $output->writeln("  composer require glueful/{$slug}:@dev");
        $output->writeln("  php glueful extensions:enable {$slug}");

        return self::SUCCESS;
    }

    /**
     * Add a path repository entry for the new extension to the app composer.json.
     * Creates the file/`repositories` key if needed; idempotent on the url.
     */
    private function registerPathRepository(string $slug, OutputInterface $output): bool
    {
        $composerPath = base_path($this->getContext(), 'composer.json');
        $url = "extensions/{$slug}";

        $data = [];
        if (is_file($composerPath)) {
            $decoded = json_decode((string) file_get_contents($composerPath), true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $repositories = is_array($data['repositories'] ?? null) ? $data['repositories'] : [];
        foreach ($repositories as $repo) {
            if (is_array($repo) && ($repo['url'] ?? null) === $url) {
                return false; // already present
            }
        }

        $repositories[] = [
            'type' => 'path',
            'url' => $url,
            'options' => ['symlink' => true],
        ];
        $data['repositories'] = array_values($repositories);

        $written = file_put_contents(
            $composerPath,
            (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
        if ($written === false) {
            $output->writeln('<comment>Could not update composer.json; add the path repository manually.</comment>');
            return false;
        }
        return true;
    }

    private function studly(string $name): string
    {
        $parts = preg_split('/[^a-zA-Z0-9]+/', $name) ?: [];
        return implode('', array_map(
            static fn(string $p): string => ucfirst(strtolower($p)),
            array_filter($parts, static fn(string $p): bool => $p !== '')
        ));
    }

    private function generateServiceProvider(string $namespace, string $studly, string $name): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Glueful\Extensions\ServiceProvider;
        use Glueful\Extensions\ExtensionManager;
        use Glueful\Bootstrap\ApplicationContext;

        final class {$studly}ServiceProvider extends ServiceProvider
        {
            public function register(ApplicationContext \$context): void
            {
                // Merge this extension's config (optional):
                // \$this->mergeConfig('{$studly}', require __DIR__ . '/../config/{$studly}.php');
            }

            public function boot(ApplicationContext \$context): void
            {
                \$this->loadRoutesFrom(__DIR__ . '/../routes/routes.php');
                \$this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

                \$this->app->get(ExtensionManager::class)->registerMeta(self::class, [
                    'slug' => '{$studly}',
                    'name' => '{$name} Extension',
                    'version' => '1.0.0',
                    'description' => '{$name} functionality for Glueful',
                ]);
            }
        }

        PHP;
    }

    private function generateRoutes(string $studly, string $slug): string
    {
        return <<<PHP
        <?php

        use Glueful\Routing\Router;

        // {$studly} extension routes
        \$router->group(['prefix' => '{$slug}'], function (Router \$router) {
            \$router->get('/', function () {
                return response()->json(['message' => 'Hello from {$studly} extension!']);
            });
        });

        PHP;
    }

    private function generateConfig(string $slug): string
    {
        return <<<PHP
        <?php

        return [
            // Configuration options for the {$slug} extension.
            'enabled' => true,
        ];

        PHP;
    }
}
