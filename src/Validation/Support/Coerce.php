<?php

declare(strict_types=1);

namespace Glueful\Validation\Support;

final class Coerce
{
    public static function int(mixed $v, ?int $default = null): ?int
    {
        if ($v === null) {
            return $default;
        }
        if (is_int($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int)$v;
        }
        return $default;
    }

    public static function bool(mixed $v, ?bool $default = null): ?bool
    {
        if ($v === null) {
            return $default;
        }
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v)) {
            $val = strtolower($v);
            if (in_array($val, ['1','true','yes','on'], true)) {
                return true;
            }
            if (in_array($val, ['0','false','no','off'], true)) {
                return false;
            }
        }
        if (is_numeric($v)) {
            return ((int)$v) !== 0;
        }
        return $default;
    }

    public static function string(mixed $v, ?string $default = null): ?string
    {
        if ($v === null) {
            return $default;
        }
        if (is_scalar($v)) {
            return (string)$v;
        }
        return $default;
    }
}
