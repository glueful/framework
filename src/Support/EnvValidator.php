<?php

declare(strict_types=1);

namespace Glueful\Support;

final class EnvValidator
{
    /**
     * Validate presence of required environment variables.
     * Returns an array with missing and present keys.
     *
     * @param string[] $keys
     * @return array{missing: string[], present: string[]}
     */
    public static function requireKeys(array $keys): array
    {
        $missing = [];
        $present = [];
        foreach ($keys as $k) {
            $val = getenv($k);
            if ($val === false || $val === '') {
                $missing[] = $k;
            } else {
                $present[] = $k;
            }
        }
        return [
            'missing' => $missing,
            'present' => $present,
        ];
    }
}
