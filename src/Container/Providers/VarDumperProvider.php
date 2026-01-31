<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\{DefinitionInterface, FactoryDefinition};

final class VarDumperProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        $debug = function_exists('env') ? (bool) env('APP_DEBUG', false) : false;
        $env = (string) ((function_exists('env') ? env('APP_ENV', '') : ($_ENV['APP_ENV'] ?? '')));
        if ($env !== 'development' || !$debug) {
            return $defs;
        }

        $defs[\Symfony\Component\VarDumper\Cloner\VarCloner::class] = new FactoryDefinition(
            \Symfony\Component\VarDumper\Cloner\VarCloner::class,
            function () {
                $cloner = new \Symfony\Component\VarDumper\Cloner\VarCloner();
                $cloner->setMaxItems(2500);
                $cloner->setMaxString(-1);
                return $cloner;
            }
        );

        $defs[\Symfony\Component\VarDumper\Dumper\CliDumper::class] = new FactoryDefinition(
            \Symfony\Component\VarDumper\Dumper\CliDumper::class,
            function () {
                $dumper = new \Symfony\Component\VarDumper\Dumper\CliDumper();
                $config = function_exists('config') ? (array) config($this->context, 'vardumper.dumpers.cli', []) : [];
                $dumper->setColors($config['colors'] ?? true);
                if (isset($config['max_string_width']) && (int) $config['max_string_width'] > 0) {
                    $dumper->setMaxStringWidth((int) $config['max_string_width']);
                }
                return $dumper;
            }
        );

        $defs[\Symfony\Component\VarDumper\Dumper\HtmlDumper::class] = new FactoryDefinition(
            \Symfony\Component\VarDumper\Dumper\HtmlDumper::class,
            function () {
                $dumper = new \Symfony\Component\VarDumper\Dumper\HtmlDumper();
                $config = function_exists('config') ? (array) config($this->context, 'vardumper.dumpers.html', []) : [];
                $dumper->setTheme($config['theme'] ?? 'dark');
                if (isset($config['file_link_format'])) {
                    $dumper->setDumpHeader($config['file_link_format']);
                }
                return $dumper;
            }
        );

        // Chosen dumper for current SAPI
        $defs['var_dumper.dumper'] = new FactoryDefinition(
            'var_dumper.dumper',
            function (\Psr\Container\ContainerInterface $c) {
                if ('cli' === PHP_SAPI) {
                    return $c->get(\Symfony\Component\VarDumper\Dumper\CliDumper::class);
                }
                return $c->get(\Symfony\Component\VarDumper\Dumper\HtmlDumper::class);
            }
        );

        return $defs;
    }
}
