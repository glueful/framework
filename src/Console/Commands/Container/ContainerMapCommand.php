<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Container;

use Glueful\Console\BaseCommand;
use Glueful\Container\Bootstrap\ContainerFactory;
use Glueful\Container\Definition\AliasDefinition;
use Glueful\Container\Autowire\AutowireDefinition;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'di:container:map',
    description: 'Dump a map of container services (ids, types, aliases)'
)]
final class ContainerMapCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Dump a map of container services (ids, types, aliases)')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: table|json', 'table')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write JSON output to file (when --format=json)')
            ->addOption(
                'deps',
                'd',
                InputOption::VALUE_NONE,
                'Include best-effort dependency adjacency for autowired services'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');
        /** @var string|null $outputPath */
        $outputPath = $input->getOption('output');
        $includeDeps = (bool) $input->getOption('deps');
        $rows = [];
        $root = dirname(__DIR__, 3);
        $manifest = $root . '/storage/cache/container/services.json';
        if (is_file($manifest)) {
            // Prefer compiled artifact when available to avoid reflection
            $json = @file_get_contents($manifest);
            $data = is_string($json) ? json_decode($json, true) : null;
            if (is_array($data)) {
                foreach ($data as $id => $meta) {
                    $rows[] = [
                        'id' => (string) $id,
                        'type' => isset($meta['type']) && is_string($meta['type']) ? $meta['type'] : '',
                        'alias_of' => isset($meta['alias_of']) && is_string($meta['alias_of']) ? $meta['alias_of'] : '',
                        'tags' => isset($meta['tags']) && is_array($meta['tags']) ? array_values($meta['tags']) : [],
                        'deps' => [], // not available without reflection
                    ];
                }
            }
        }

        if ($rows === []) {
            // Fallback: build from runtime container (dev or when manifest absent)
            $container = ContainerFactory::create(false);
            $ref = new \ReflectionClass($container);
            $prop = $ref->getProperty('definitions');
            $prop->setAccessible(true);
            /** @var array<string, mixed> $defs */
            $defs = (array) $prop->getValue($container);

            // Build service->tags map by inspecting tagged iterator definitions
            $serviceTags = [];
            foreach ($defs as $id => $def) {
                if ($def instanceof \Glueful\Container\Definition\TaggedIteratorDefinition) {
                    $entries = $def->getTagged();
                    foreach ($entries as $entry) {
                        $sid = $entry['service'];
                        $serviceTags[$sid] = $serviceTags[$sid] ?? [];
                        $serviceTags[$sid][] = (string) $id; // $id is tag name
                    }
                }
            }

            foreach ($defs as $id => $def) {
                $type = is_object($def) ? get_class($def) : gettype($def);
                $aliasTarget = ($def instanceof AliasDefinition) ? $def->getTarget() : '';
                $tags = $serviceTags[$id] ?? [];
                $deps = [];
                if ($includeDeps && $def instanceof AutowireDefinition) {
                    $class = $def->getClass();
                    try {
                        $rc = new \ReflectionClass($class);
                        $ctor = $rc->getConstructor();
                        if ($ctor !== null) {
                            foreach ($ctor->getParameters() as $param) {
                                $t = $param->getType();
                                if ($t instanceof \ReflectionNamedType && !$t->isBuiltin()) {
                                    $deps[] = $t->getName();
                                }
                            }
                        }
                    } catch (\Throwable) {
                        // ignore reflection failures
                    }
                }
                $rows[] = [
                    'id' => (string) $id,
                    'type' => $type,
                    'alias_of' => $aliasTarget,
                    'tags' => $tags,
                    'deps' => $deps,
                ];
            }
        }

        if ($format === 'json') {
            $json = json_encode($rows, JSON_PRETTY_PRINT);
            if (is_string($json)) {
                if (is_string($outputPath) && $outputPath !== '') {
                    file_put_contents($outputPath, $json);
                } else {
                    $output->writeln($json);
                }
            }
            return self::SUCCESS;
        }

        $this->table(['Service ID', 'Definition Type', 'Alias Of', 'Tags'], array_map(
            fn($r) => [$r['id'], $r['type'], $r['alias_of'], implode(', ', $r['tags'])],
            $rows
        ));
        return self::SUCCESS;
    }
}
