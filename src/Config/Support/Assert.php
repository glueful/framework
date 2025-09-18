<?php

declare(strict_types=1);

namespace Glueful\Config\Support;

final class Assert
{
    /**
     * @param array<string, mixed> $a
     */
    public static function string(array $a, string $k): string
    {
        if (!isset($a[$k]) || !is_string($a[$k])) {
            throw new \InvalidArgumentException("Expected string: {$k}");
        }
        return $a[$k];
    }
}
