<?php

declare(strict_types=1);

namespace Glueful\Config\Validation;

use Glueful\Config\Contracts\ConfigValidatorInterface;

final class ConfigValidator implements ConfigValidatorInterface
{
    public function validate(array $input, array $schema): array
    {
        $out = [];
        $errors = [];

        foreach ($schema as $key => $rules) {
            $has = array_key_exists($key, $input);
            $val = $has ? $input[$key] : ($rules['default'] ?? null);

            if ((bool)($rules['required'] ?? false) && !$has) {
                $errors[] = "Missing required: {$key}";
                continue;
            }

            if ($val !== null && isset($rules['type']) && gettype($val) !== $rules['type']) {
                $errors[] = "Invalid type for {$key}: expected {$rules['type']}, got " . gettype($val);
                continue;
            }

            if (isset($rules['enum']) && $val !== null && !in_array($val, $rules['enum'], true)) {
                $errors[] = "{$key} must be one of: " . implode(', ', $rules['enum']);
            }

            if (isset($rules['min']) && is_int($val) && $val < $rules['min']) {
                $errors[] = "{$key} must be >= {$rules['min']}";
            }
            if (isset($rules['max']) && is_int($val) && $val > $rules['max']) {
                $errors[] = "{$key} must be <= {$rules['max']}";
            }

            if (isset($rules['schema']) && is_array($val) && is_array($rules['schema'])) {
                $nested = $this->validate($val, $rules['schema']);
                $val = $nested;
            }

            $out[$key] = $val;
        }

        if (count($errors) > 0) {
            throw new \InvalidArgumentException('Config validation failed: ' . implode('; ', $errors));
        }

        return $out;
    }
}
