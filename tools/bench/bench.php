<?php

declare(strict_types=1);

// Simple benchmark harness for Glueful routing dispatch

require __DIR__ . '/../../vendor/autoload.php';

use Glueful\Framework;
use Glueful\Routing\Router;
use Symfony\Component\HttpFoundation\Request;
use Glueful\Http\Response as GluefulResponse;

// Repeat helper: runs callable N times and returns total ms
$timeLoop = static function (callable $fn, int $n = 1000): float {
    $start = microtime(true);
    for ($i = 0; $i < $n; $i++) {
        $fn();
    }
    return (microtime(true) - $start) * 1000.0;
};

$iterations = (int) ($argv[1] ?? 1000);

// Boot framework
$fw = Framework::create(getcwd());
$app = $fw->boot();

/** @var Router $router */
$router = $app->getContainer()->get(Router::class);

// Static route benchmark
$router->get('/bench/static', fn() => new GluefulResponse(['ok' => true]));
$req = Request::create('/bench/static', 'GET');

// Warm-up
$router->dispatch($req);

$ms = $timeLoop(fn() => $router->dispatch($req), $iterations);
echo sprintf("Dispatch x%d: %.2fms\n", $iterations, $ms);
