<?php

declare(strict_types=1);

namespace Glueful\Installer;

final class InstallResult
{
    /** @param list<InstallStep> $steps */
    public function __construct(public readonly array $steps, public readonly bool $ok)
    {
    }

    /** @param list<InstallStep> $steps */
    public static function from(array $steps): self
    {
        $ok = true;
        foreach ($steps as $step) {
            if ($step->status === InstallStep::FAILED) {
                $ok = false;
                break;
            }
        }
        return new self($steps, $ok);
    }
}
