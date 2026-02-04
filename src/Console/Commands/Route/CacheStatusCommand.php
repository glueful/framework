<?php

namespace Glueful\Console\Commands\Route;

use Glueful\Console\BaseCommand;
use Glueful\Routing\RouteCache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'route:cache:status',
    description: 'Show route cache status and signature details'
)]
class CacheStatusCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Show route cache status and signature details')
            ->setHelp('Displays the current route cache signature, cache file status, ' .
                'and the route source files used to compute the signature.')
            ->addOption(
                'files',
                null,
                InputOption::VALUE_NONE,
                'List all route source files used for signature computation'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var RouteCache $cache */
        $cache = $this->getService(RouteCache::class);
        $cacheFile = $cache->getCacheFilePath();
        $cachedSignature = $cache->getCachedSignature();
        $currentSignature = $cache->getSignature();

        $cacheExists = file_exists($cacheFile);
        $cacheMtime = $cacheExists ? date('c', (int) filemtime($cacheFile)) : 'n/a';
        $isValid = $cachedSignature !== null && hash_equals($cachedSignature, $currentSignature);

        $headers = ['Route Cache', 'Value'];
        $rows = [
            ['Cache file', $cacheFile],
            ['Cache exists', $cacheExists ? 'yes' : 'no'],
            ['Cache mtime', $cacheMtime],
            ['Cached signature', $cachedSignature ?? 'n/a'],
            ['Current signature', $currentSignature],
            ['Signature valid', $isValid ? 'yes' : 'no'],
            ['Source files', (string) count($cache->getSourceFiles())],
        ];

        $this->table($headers, $rows);

        if ((bool) $input->getOption('files')) {
            $this->line('');
            $this->info('Route source files:');
            foreach ($cache->getSourceFiles() as $file) {
                $this->line('  - ' . $file);
            }
        }

        if (!$isValid) {
            $this->line('');
            $this->warning(
                'Route cache is stale or missing. Regenerate by re-running your app or deleting the cache file.'
            );
        }

        return self::SUCCESS;
    }
}
