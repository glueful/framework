<?php

declare(strict_types=1);

// Child for ExtensionFilesOpcacheTest, run with OPcache on and timestamp checks off (as a
// production PHP-FPM pool may be): `config <base>` writes config/extensions.php through the state
// writer after it was compiled, `cache <base>` rewrites bootstrap/cache/extensions.php through the
// extension manager. Each prints the providers a second require sees, as JSON; `none` when OPcache
// isn't available in this PHP.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ExtensionStateWriter;
use Glueful\Extensions\ServiceProvider;
use Psr\Container\ContainerInterface;

final class OpcacheChildAlphaProvider extends ServiceProvider
{
}

final class OpcacheChildBetaProvider extends ServiceProvider
{
}

if (!function_exists('opcache_get_status') || opcache_get_status(false) === false) {
    echo "none\n";
    exit(0);
}

[, $mode, $base] = $argv;

if ($mode === 'config') {
    $path = $base . '/config/extensions.php';
    file_put_contents($path, "<?php\n\nreturn [\n    'enabled' => [\n    ],\n];\n");
    require $path;                                                   // compiled into OPcache
    (new ExtensionStateWriter())->enable($path, OpcacheChildAlphaProvider::class);
    echo json_encode((require $path)['enabled']), "\n";
    exit(0);
}

$context = new ApplicationContext($base, 'testing', ['framework' => $base . '/config', 'application' => $base . '/config']);
$context->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));
$container = new class ($context) implements ContainerInterface {
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function get(string $id): mixed
    {
        return $id === ApplicationContext::class ? $this->context : throw new RuntimeException("Unexpected: {$id}");
    }

    public function has(string $id): bool
    {
        return $id === ApplicationContext::class;
    }
};
$context->setContainer($container);
$manager = new ExtensionManager($container);
$cache = $base . '/bootstrap/cache/extensions.php';
$manager->writeCacheNow([OpcacheChildAlphaProvider::class]);
require $cache;                                                      // compiled into OPcache
$manager->writeCacheNow([OpcacheChildAlphaProvider::class, OpcacheChildBetaProvider::class]);
echo json_encode((require $cache)['providers']), "\n";
