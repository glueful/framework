<?php

declare(strict_types=1);

namespace Glueful\DI\DSL;

final class Utils
{
    public static function ref(string $id): string
    {
        return '@' . $id;
    }
    public static function param(string $key): string
    {
        return '%' . $key . '%';
    }
}
