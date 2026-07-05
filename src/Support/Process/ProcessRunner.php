<?php

declare(strict_types=1);

namespace Glueful\Support\Process;

/**
 * A blocking external-command runner, injected so command classes can be tested
 * without spawning real processes (composer, a child PHP invocation).
 */
interface ProcessRunner
{
    /**
     * @param list<string> $cmd argv (no shell)
     * @param callable(string):void|null $onOutput receives each output chunk
     * @return array{exitCode:int,output:string}
     */
    public function run(array $cmd, string $cwd, float $timeout, ?callable $onOutput = null): array;
}
