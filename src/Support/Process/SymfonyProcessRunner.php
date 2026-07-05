<?php

declare(strict_types=1);

namespace Glueful\Support\Process;

use Symfony\Component\Process\Process;

final class SymfonyProcessRunner implements ProcessRunner
{
    public function run(array $cmd, string $cwd, float $timeout, ?callable $onOutput = null): array
    {
        $process = new Process($cmd, $cwd);
        $process->setTimeout($timeout);
        $process->run(function (string $type, string $buffer) use ($onOutput): void {
            if ($onOutput !== null) {
                $onOutput($buffer);
            }
        });

        return [
            'exitCode' => $process->getExitCode() ?? 1,
            'output' => $process->getOutput() . $process->getErrorOutput(),
        ];
    }
}
