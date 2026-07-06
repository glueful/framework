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
     * @param array<string,string>|null $env explicit environment for the child; null
     *        inherits the parent's. Pass a complete env (not just overrides) so it
     *        does not depend on Symfony's version-specific inheritance/merge behavior.
     * @return array{exitCode:int,output:string}
     */
    public function run(array $cmd, string $cwd, float $timeout, ?callable $onOutput = null, ?array $env = null): array;
}
