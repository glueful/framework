<?php

namespace Glueful\Console\Commands\Cache;

use Glueful\Cache\CacheStore;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cache:inspect',
    description: 'Inspect cache driver and extension status'
)]
class InspectCommand extends BaseCommand
{
    /** @var CacheStore<mixed> */
    private CacheStore $cacheStore;

    public function __construct()
    {
        parent::__construct();
        $this->cacheStore = $this->getService(CacheStore::class);
    }

    protected function configure(): void
    {
        $this->setDescription('Inspect cache driver and extension status')
            ->setHelp('Shows cache driver configuration, extension availability, and runtime stats.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $driver = (string) config($this->getContext(), 'cache.default', 'unknown');
        $prefix = (string) config($this->getContext(), 'cache.prefix', '');
        $extensionStatus = $this->checkDriverExtension($driver);

        $headers = ['Cache', 'Value'];
        $rows = [
            ['Driver', $driver],
            ['Prefix', $prefix === '' ? '(none)' : $prefix],
            ['Extension', $extensionStatus['message']],
        ];

        $this->table($headers, $rows);

        $this->line('');
        $this->info('Capabilities:');
        $caps = $this->cacheStore->getCapabilities();
        if ($caps === []) {
            $this->line('  (none)');
        } else {
            foreach ($caps as $cap) {
                $this->line('  - ' . $cap);
            }
        }

        $this->line('');
        $this->info('Stats:');
        try {
            $stats = $this->cacheStore->getStats();
            if ($stats === []) {
                $this->line('  (none)');
            } else {
                foreach ($stats as $key => $value) {
                    $this->line('  ' . $key . ': ' . $this->formatStatValue($value));
                }
            }
        } catch (\Throwable $e) {
            $this->warning('  Stats unavailable: ' . $e->getMessage());
        }

        if ($extensionStatus['ok'] === false) {
            $this->line('');
            $this->warning($extensionStatus['hint']);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{ok: bool, message: string, hint: string}
     */
    private function checkDriverExtension(string $driver): array
    {
        return match (strtolower($driver)) {
            'redis' => $this->checkExtension('redis', 'Install the PHP Redis extension.'),
            'memcached' => $this->checkExtension('memcached', 'Install the PHP Memcached extension.'),
            default => [
                'ok' => true,
                'message' => 'n/a',
                'hint' => '',
            ],
        };
    }

    /**
     * @return array{ok: bool, message: string, hint: string}
     */
    private function checkExtension(string $ext, string $hint): array
    {
        $ok = extension_loaded($ext);
        return [
            'ok' => $ok,
            'message' => $ok ? $ext . ' loaded' : $ext . ' missing',
            'hint' => $ok ? '' : $hint,
        ];
    }

    /**
     * @param mixed $value
     */
    private function formatStatValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            return json_encode($value) ?: '[]';
        }
        if (is_object($value)) {
            return get_class($value);
        }
        if (is_numeric($value)) {
            return number_format((float) $value);
        }
        return (string) $value;
    }
}
