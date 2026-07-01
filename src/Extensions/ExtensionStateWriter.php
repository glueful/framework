<?php

declare(strict_types=1);

namespace Glueful\Extensions;

/**
 * The single writer for config/extensions.php's `enabled` array. Works only with a
 * flat list of plain string FQCNs; refuses to edit a non-trivial array (conditionals,
 * function calls, ::class constants, non-string entries) and tells the caller to edit
 * by hand — keeping writes safe without a PHP parser.
 */
final class ExtensionStateWriter
{
    public function enable(string $configPath, string $provider, bool $dryRun = false, bool $backup = false): void
    {
        $provider = ltrim($provider, '\\');
        $list = $this->readList($configPath);
        if (in_array($provider, $list, true)) {
            return; // idempotent
        }
        $list[] = $provider;
        sort($list, SORT_STRING);
        $this->writeList($configPath, $list, $dryRun, $backup);
    }

    public function disable(string $configPath, string $provider, bool $dryRun = false, bool $backup = false): void
    {
        $provider = ltrim($provider, '\\');
        $list = $this->readList($configPath);
        $next = array_values(array_filter($list, static fn($p) => $p !== $provider));
        if ($next === $list) {
            return; // not present
        }
        $this->writeList($configPath, $next, $dryRun, $backup);
    }

    /** @return list<string> */
    private function readList(string $configPath): array
    {
        if (!is_file($configPath)) {
            throw new \RuntimeException("Config not found: {$configPath}");
        }
        $config = require $configPath;
        if (!is_array($config) || !array_key_exists('enabled', $config)) {
            throw new \RuntimeException("Config has no 'enabled' key: {$configPath}");
        }
        $enabled = $config['enabled'];
        if (!is_array($enabled)) {
            throw new \RuntimeException("'enabled' is not an array: {$configPath}");
        }
        foreach ($enabled as $entry) {
            if (!is_string($entry)) {
                throw new \RuntimeException(
                    "'enabled' contains a non-string entry; refuse to auto-edit {$configPath} — edit it by hand."
                );
            }
        }
        // Reject conditional/function-call arrays by re-checking the SOURCE for an
        // `'enabled' => [ ...only string literals... ]` shape.
        $src = (string) file_get_contents($configPath);
        if (!$this->enabledArrayIsLiteral($src)) {
            throw new \RuntimeException(
                "'enabled' in {$configPath} is not a flat list of string literals; edit it by hand."
            );
        }
        /** @var list<string> $enabled */
        return array_values($enabled);
    }

    private function enabledArrayIsLiteral(string $src): bool
    {
        // Capture the enabled => [ ... ] block and ensure that, after removing
        // comments and string literals, nothing but commas/whitespace remains —
        // i.e. no conditionals, function calls, ::class, or non-string entries.
        if (!preg_match("/'enabled'\\s*=>\\s*\\[(.*?)\\]/s", $src, $m)) {
            return false;
        }
        $body = $m[1];
        // 1) strip block comments /* ... */
        $body = preg_replace('#/\*.*?\*/#s', '', $body);
        // 2) strip line comments // ... and # ... (to end of line)
        $body = preg_replace('@//[^\r\n]*|#[^\r\n]*@', '', (string) $body);
        // 3) strip single-quoted string literals (the only allowed value form)
        $body = preg_replace("/'(?:\\\\.|[^'\\\\])*'/s", '', (string) $body);
        // 4) what's left must be only commas + whitespace
        $body = str_replace(',', '', (string) $body);
        return trim((string) $body) === '';
    }

    /** @param list<string> $list */
    private function writeList(string $configPath, array $list, bool $dryRun, bool $backup): void
    {
        $items = '';
        foreach ($list as $p) {
            $items .= "        '" . str_replace('\\', '\\\\', $p) . "',\n";
        }
        $src = (string) file_get_contents($configPath);
        // Consume the whitespace before the closing bracket into a non-captured `\s*` (it
        // already holds the newline + indent) and re-emit the closing indent + bracket
        // literally. Capturing it as `$3` and prefixing "    " left a 4-space dangling line.
        $updated = preg_replace(
            "/('enabled'\\s*=>\\s*\\[)(.*?)\\s*\\]/s",
            "$1\n" . $items . "    ]",
            $src,
            1
        );
        if ($updated === null) {
            throw new \RuntimeException("Failed to rewrite 'enabled' in {$configPath}");
        }
        if ($dryRun) {
            return;
        }
        if ($backup) {
            copy($configPath, $configPath . '.bak');
        }
        file_put_contents($configPath, $updated);
    }
}
