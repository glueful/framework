<?php

declare(strict_types=1);

namespace Glueful\Installer;

/**
 * The single `.env` reader/writer. Atomic (temp file + rename), quotes values that need it,
 * updates a key in place or appends it at the end, and preserves comments/order.
 */
final class EnvWriter
{
    public function __construct(private readonly string $envPath)
    {
    }

    public function get(string $key): ?string
    {
        if (!is_file($this->envPath)) {
            return null;
        }
        foreach (explode("\n", (string) file_get_contents($this->envPath)) as $line) {
            if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/', $line, $m) === 1) {
                return $this->unquote($m[1]);
            }
        }
        return null;
    }

    public function set(string $key, string $value): void
    {
        $this->setMany([$key => $value]);
    }

    /** @param array<string, string> $pairs */
    public function setMany(array $pairs): void
    {
        $content = is_file($this->envPath) ? (string) file_get_contents($this->envPath) : '';
        $lines = $content === '' ? [] : explode("\n", rtrim($content, "\n"));

        foreach ($pairs as $key => $value) {
            $newLine = $key . '=' . $this->quote($value);
            $found = false;
            foreach ($lines as $i => $line) {
                if (preg_match('/^' . preg_quote($key, '/') . '=/', $line) === 1) {
                    $lines[$i] = $newLine;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $lines[] = $newLine;
            }
        }

        $this->atomicWrite(implode("\n", $lines) . "\n");
    }

    private function quote(string $value): string
    {
        // Safe bare values: alnum and a few path/url chars.
        if ($value === '' || preg_match('/^[A-Za-z0-9_.\/:@-]+$/', $value) === 1) {
            return $value;
        }
        $escaped = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
        return '"' . $escaped . '"';
    }

    private function unquote(string $raw): string
    {
        $raw = trim($raw);
        if (strlen($raw) >= 2 && $raw[0] === '"' && substr($raw, -1) === '"') {
            $inner = substr($raw, 1, -1);
            return str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $inner);
        }
        return $raw;
    }

    private function atomicWrite(string $content): void
    {
        $dir = dirname($this->envPath);
        $tmp = tempnam($dir, '.env.tmp.');
        if ($tmp === false) {
            throw new \RuntimeException("Cannot create temp file in {$dir}");
        }
        file_put_contents($tmp, $content);
        if (!rename($tmp, $this->envPath)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write {$this->envPath}");
        }
    }
}
