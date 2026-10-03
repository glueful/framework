<?php

declare(strict_types=1);

// Child process for ExtensionStateMutexTest: takes the extension-state mutex and adds one provider
// to a shared enabled list. With --pause it signals `holding` once the mutex is held and waits for a
// line on stdin before writing, so the parent can start a second writer while it holds the lock.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionStateMutex;
use Glueful\Extensions\ExtensionStateWriter;

[, $base, $provider] = $argv;
$pause = in_array('--pause', $argv, true);

$context = new ApplicationContext($base, 'testing');
if ($pause) {
    ExtensionStateMutex::$afterAcquire = static function (): void {
        fwrite(STDOUT, "holding\n");
        fflush(STDOUT);
        fgets(STDIN);
    };
}
fwrite(STDOUT, "attempting\n");
fflush(STDOUT);
ExtensionStateMutex::within($context, static function () use ($base, $provider): void {
    (new ExtensionStateWriter())->enable($base . '/config/extensions.php', $provider);
});
fwrite(STDOUT, "done\n");
