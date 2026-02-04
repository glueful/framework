<?php

namespace Glueful\Console\Commands\Route;

use Glueful\Console\BaseCommand;
use Glueful\Routing\RouteCache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'route:cache:clear',
    description: 'Clear the route cache file'
)]
class CacheClearCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Clear the route cache file')
            ->setHelp('Removes the compiled route cache file for the current environment.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var RouteCache $cache */
        $cache = $this->getService(RouteCache::class);
        $cacheFile = $cache->getCacheFilePath();

        if (!file_exists($cacheFile)) {
            $this->info('No route cache file exists.');
            return self::SUCCESS;
        }

        $cache->clear();

        $this->success('Route cache cleared.');
        $this->line('Cache file: ' . $cacheFile);

        return self::SUCCESS;
    }
}
