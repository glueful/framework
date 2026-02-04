<?php

namespace Glueful\Console\Commands\Route;

use Glueful\Console\BaseCommand;
use Glueful\Routing\RouteManifest;
use Glueful\Routing\Router;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'route:debug',
    description: 'Dump resolved routes with middleware and handlers'
)]
class DebugCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Dump resolved routes with middleware and handlers')
            ->setHelp('Lists routes after loading route files and attribute routes.')
            ->addOption('method', null, InputOption::VALUE_REQUIRED, 'Filter by HTTP method')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Filter by path substring')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Filter by route name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var Router $router */
        $router = $this->getService(Router::class);

        // Load routes
        RouteManifest::load($router, $this->getContext());
        $controllers = base_path($this->getContext(), 'app/Controllers');
        if (is_dir($controllers) && $this->getContainer()->has(\Glueful\Routing\AttributeRouteLoader::class)) {
            $loader = $this->getService(\Glueful\Routing\AttributeRouteLoader::class);
            $loader->scanDirectory($controllers);
        }

        $routes = $router->getAllRoutes();
        $methodFilter = strtoupper((string) $input->getOption('method'));
        $pathFilter = (string) $input->getOption('path');
        $nameFilter = (string) $input->getOption('name');

        $filtered = [];
        foreach ($routes as $route) {
            if ($methodFilter !== '' && strtoupper((string) $route['method']) !== $methodFilter) {
                continue;
            }
            if ($pathFilter !== '' && !str_contains((string) $route['path'], $pathFilter)) {
                continue;
            }
            if ($nameFilter !== '' && (string) $route['name'] !== $nameFilter) {
                continue;
            }
            $filtered[] = $route;
        }

        $rows = [];
        foreach ($filtered as $route) {
            $rows[] = [
                $route['method'],
                $route['path'],
                $this->formatHandler($route['handler']),
                $route['name'] ?? '',
                implode(',', $route['middleware'] ?? []),
                $route['type'],
            ];
        }

        $this->table(['Method', 'Path', 'Handler', 'Name', 'Middleware', 'Type'], $rows);
        $this->line('Total: ' . count($filtered));

        return self::SUCCESS;
    }

    private function formatHandler(mixed $handler): string
    {
        if (is_array($handler)) {
            return $handler[0] . '::' . $handler[1];
        }
        if ($handler instanceof \Closure) {
            return 'Closure';
        }
        if (is_string($handler)) {
            return $handler;
        }
        if (is_object($handler)) {
            return get_class($handler);
        }
        return gettype($handler);
    }
}
