<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Sqlite;

/**
 * Character-level scanner for SQLite DDL/SQL text.
 *
 * Understands single-quoted string literals, double/backtick/bracket quoted
 * identifiers (including doubled quote escapes), -- and slash-star comments, and
 * parenthesis depth. It is NOT a SQL parser: it answers the narrow
 * questions the rebuild audit needs, and throws rather than guessing when
 * a construct is unterminated.
 */
final class SqliteSqlScanner
{
    private const KEYWORDS = [
        'select', 'from', 'where', 'and', 'or', 'not', 'in', 'is', 'null', 'like', 'glob',
        'between', 'case', 'when', 'then', 'else', 'end', 'exists', 'cast', 'as', 'on',
        'create', 'table', 'view', 'trigger', 'index', 'if', 'temp', 'temporary',
        'primary', 'key', 'unique', 'check', 'default', 'references', 'foreign',
        'constraint', 'collate', 'autoincrement', 'without', 'rowid', 'strict',
        'conflict', 'match', 'deferrable', 'initially', 'virtual',
        'integer', 'text', 'real', 'blob', 'numeric', 'varchar', 'boolean', 'datetime',
        'insert', 'update', 'delete', 'begin', 'instead', 'of', 'for', 'each', 'row',
        'values', 'set', 'join', 'left', 'inner', 'outer', 'group', 'by', 'order',
        'asc', 'desc', 'limit', 'distinct', 'union', 'all', 'current_timestamp',
        'current_date', 'current_time', 'true', 'false',
    ];

    /**
     * Tokenize into structural events. Each event is one of:
     * ['ident', string $nameLower], ['word', string $wordLower],
     * ['literal', string $rawLiteral], ['punct', string $char]. Comments
     * are consumed silently.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function tokenize(string $sql): array
    {
        $tokens = [];
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            // -- line comment
            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline + 1;
                continue;
            }
            // /* block comment */
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $close = strpos($sql, '*/', $i + 2);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated block comment in SQL');
                }
                $i = $close + 2;
                continue;
            }
            // 'string literal' with '' escape. Keep a literal event so the
            // enum-shape validator can prove the IN-list contains exactly
            // comma-separated string literals; dependency scans still ignore it.
            if ($char === "'") {
                $end = $this->consumeQuoted($sql, $i, "'");
                $tokens[] = ['literal', substr($sql, $i, $end - $i)];
                $i = $end;
                continue;
            }
            // "quoted identifier" with "" escape
            if ($char === '"') {
                $end = $this->consumeQuoted($sql, $i, '"');
                $raw = substr($sql, $i + 1, $end - $i - 2);
                $tokens[] = ['ident', strtolower(str_replace('""', '"', $raw))];
                $i = $end;
                continue;
            }
            // `backtick identifier`
            if ($char === '`') {
                $end = $this->consumeQuoted($sql, $i, '`');
                $tokens[] = ['ident', strtolower(substr($sql, $i + 1, $end - $i - 2))];
                $i = $end;
                continue;
            }
            // [bracket identifier]
            if ($char === '[') {
                $close = strpos($sql, ']', $i + 1);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated bracket identifier in SQL');
                }
                $tokens[] = ['ident', strtolower(substr($sql, $i + 1, $close - $i - 1))];
                $i = $close + 1;
                continue;
            }
            // bare word
            if (preg_match('/[A-Za-z_]/', $char) === 1) {
                $j = $i + 1;
                while ($j < $length && preg_match('/[A-Za-z0-9_]/', $sql[$j]) === 1) {
                    $j++;
                }
                $word = strtolower(substr($sql, $i, $j - $i));
                $tokens[] = [in_array($word, self::KEYWORDS, true) ? 'word' : 'ident', $word];
                $i = $j;
                continue;
            }
            if ($char === '(' || $char === ')' || $char === ',' || $char === '*') {
                $tokens[] = ['punct', $char];
            }
            $i++;
        }

        return $tokens;
    }

    /** @return int Position just past the closing quote */
    private function consumeQuoted(string $sql, int $start, string $quote): int
    {
        $i = $start + 1;
        $length = strlen($sql);
        while ($i < $length) {
            if ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i += 2;
                    continue;
                }
                return $i + 1;
            }
            $i++;
        }

        throw new \RuntimeException("Unterminated {$quote}-quoted token in SQL");
    }

    /**
     * Every identifier referenced in the SQL, lower-cased, deduplicated.
     *
     * @return list<string>
     */
    public function identifiers(string $sql): array
    {
        $out = [];
        foreach ($this->tokenize($sql) as [$kind, $value]) {
            if ($kind === 'ident' && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * Extract every CHECK (...) expression from a CREATE TABLE statement.
     * Ownership comes from the top-level clause containing the CHECK, not
     * from how many identifiers happen to occur in its expression.
     *
     * @return list<array{
     *   expression: string,
     *   identifiers: list<string>,
     *   scope: 'column'|'table',
     *   column: ?string
     * }>
     */
    public function extractChecks(string $createTableSql): array
    {
        $checks = [];
        foreach ($this->topLevelTableClauses($createTableSql) as $clause) {
            [$scope, $column] = $this->classifyTableClause($clause);
            foreach ($this->extractCheckExpressions($clause) as $check) {
                $checks[] = [
                    ...$check,
                    'scope' => $scope,
                    'column' => $column,
                ];
            }
        }

        return $checks;
    }

    /**
     * @return list<array{expression: string, identifiers: list<string>}>
     */
    private function extractCheckExpressions(string $sql): array
    {
        $checks = [];
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline + 1;
                continue;
            }
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $close = strpos($sql, '*/', $i + 2);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated block comment in CREATE TABLE SQL');
                }
                $i = $close + 2;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $i = $this->consumeQuoted($sql, $i, $char);
                continue;
            }
            if ($char === '[') {
                $close = strpos($sql, ']', $i + 1);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated bracket identifier in CREATE TABLE SQL');
                }
                $i = $close + 1;
                continue;
            }
            if (preg_match('/[A-Za-z_]/', $char) === 1) {
                $j = $i + 1;
                while ($j < $length && preg_match('/[A-Za-z0-9_]/', $sql[$j]) === 1) {
                    $j++;
                }
                $word = strtolower(substr($sql, $i, $j - $i));
                $i = $j;
                if ($word === 'check') {
                    $open = $this->nextCodePosition($sql, $i);
                    if ($open === null || $sql[$open] !== '(') {
                        throw new \RuntimeException('CHECK keyword without parenthesized expression');
                    }
                    $close = $this->matchParen($sql, $open);
                    $expression = trim(substr($sql, $open + 1, $close - $open - 1));
                    $checks[] = [
                        'expression' => $expression,
                        'identifiers' => $this->identifiers($expression),
                    ];
                    $i = $close + 1;
                }
                continue;
            }
            $i++;
        }

        return $checks;
    }

    private function nextCodePosition(string $sql, int $from): ?int
    {
        $length = strlen($sql);
        $i = $from;
        while ($i < $length) {
            if (preg_match('/\s/', $sql[$i]) === 1) {
                $i++;
                continue;
            }
            if ($sql[$i] === '-' && ($sql[$i + 1] ?? '') === '-') {
                $newline = strpos($sql, "\n", $i + 2);
                $i = $newline === false ? $length : $newline + 1;
                continue;
            }
            if ($sql[$i] === '/' && ($sql[$i + 1] ?? '') === '*') {
                $close = strpos($sql, '*/', $i + 2);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated block comment in SQL');
                }
                $i = $close + 2;
                continue;
            }

            return $i;
        }

        return null;
    }

    /** @return list<string> */
    private function topLevelTableClauses(string $sql): array
    {
        $tokensStart = $this->nextCodePosition($sql, 0);
        if ($tokensStart === null) {
            throw new \RuntimeException('Empty CREATE TABLE SQL');
        }

        // Find the CREATE TABLE column-list opener while honoring every
        // quoted/comment form. Parentheses in comments or identifiers do not count.
        $open = null;
        $length = strlen($sql);
        for ($i = $tokensStart; $i < $length;) {
            $char = $sql[$i];
            if ($char === "'" || $char === '"' || $char === '`') {
                $i = $this->consumeQuoted($sql, $i, $char);
                continue;
            }
            if ($char === '[') {
                $close = strpos($sql, ']', $i + 1);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated bracket identifier in SQL');
                }
                $i = $close + 1;
                continue;
            }
            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $newline = strpos($sql, "\n", $i + 2);
                $i = $newline === false ? $length : $newline + 1;
                continue;
            }
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $close = strpos($sql, '*/', $i + 2);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated block comment in SQL');
                }
                $i = $close + 2;
                continue;
            }
            if ($char === '(') {
                $open = $i;
                break;
            }
            $i++;
        }
        if ($open === null) {
            throw new \RuntimeException('CREATE TABLE SQL has no column list');
        }

        $close = $this->matchParen($sql, $open);
        $body = substr($sql, $open + 1, $close - $open - 1);
        $clauses = [];
        $start = 0;
        $depth = 0;
        $bodyLength = strlen($body);
        for ($i = 0; $i < $bodyLength;) {
            $char = $body[$i];
            if ($char === "'" || $char === '"' || $char === '`') {
                $i = $this->consumeQuoted($body, $i, $char);
                continue;
            }
            if ($char === '[') {
                $end = strpos($body, ']', $i + 1);
                if ($end === false) {
                    throw new \RuntimeException('Unterminated bracket identifier in table clause');
                }
                $i = $end + 1;
                continue;
            }
            if ($char === '-' && ($body[$i + 1] ?? '') === '-') {
                $newline = strpos($body, "\n", $i + 2);
                $i = $newline === false ? $bodyLength : $newline + 1;
                continue;
            }
            if ($char === '/' && ($body[$i + 1] ?? '') === '*') {
                $end = strpos($body, '*/', $i + 2);
                if ($end === false) {
                    throw new \RuntimeException('Unterminated block comment in table clause');
                }
                $i = $end + 2;
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth < 0) {
                    throw new \RuntimeException('Unbalanced table-clause parentheses');
                }
            } elseif ($char === ',' && $depth === 0) {
                $clauses[] = trim(substr($body, $start, $i - $start));
                $start = $i + 1;
            }
            $i++;
        }
        if ($depth !== 0) {
            throw new \RuntimeException('Unbalanced table-clause parentheses');
        }
        $clauses[] = trim(substr($body, $start));

        return array_values(array_filter($clauses, static fn (string $clause): bool => $clause !== ''));
    }

    /** @return array{0: 'column'|'table', 1: ?string} */
    private function classifyTableClause(string $clause): array
    {
        $tokens = $this->tokenize($clause);
        if ($tokens === []) {
            throw new \RuntimeException('Empty CREATE TABLE clause');
        }
        [$kind, $value] = $tokens[0];
        if ($kind === 'ident') {
            return ['column', $value];
        }
        if ($kind === 'word' && in_array($value, ['constraint', 'check', 'primary', 'unique', 'foreign'], true)) {
            return ['table', null];
        }

        throw new \RuntimeException('Cannot determine CREATE TABLE clause ownership');
    }

    /** @return int Position of the matching close paren */
    private function matchParen(string $sql, int $openPos): int
    {
        $depth = 0;
        $length = strlen($sql);
        $i = $openPos;

        while ($i < $length) {
            $char = $sql[$i];
            if ($char === "'" || $char === '"' || $char === '`') {
                $i = $this->consumeQuoted($sql, $i, $char);
                continue;
            }
            if ($char === '[') {
                $close = strpos($sql, ']', $i + 1);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated bracket identifier in SQL');
                }
                $i = $close + 1;
                continue;
            }
            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline + 1;
                continue;
            }
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $close = strpos($sql, '*/', $i + 2);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated block comment in SQL');
                }
                $i = $close + 2;
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
            $i++;
        }

        throw new \RuntimeException('Unbalanced parentheses in SQL');
    }

    /**
     * Exactly the framework's enum-emulation shape: `<col> IN (<literals>)`,
     * where <col> may be bare or quoted, and nothing else surrounds it.
     */
    public function isEnumCheckShape(string $expression, string $column): bool
    {
        $tokens = $this->tokenize($expression);
        if (count($tokens) < 5) {
            return false;
        }
        [$kind0, $value0] = $tokens[0];
        [$kind1, $value1] = $tokens[1];
        if ($kind0 !== 'ident' || $value0 !== strtolower($column)) {
            return false;
        }
        if ($kind1 !== 'word' || $value1 !== 'in') {
            return false;
        }
        if ($tokens[2] !== ['punct', '(']) {
            return false;
        }
        $last = $tokens[count($tokens) - 1];
        if ($last !== ['punct', ')']) {
            return false;
        }
        // Exactly: literal (',' literal)*. Empty lists, numeric expressions,
        // adjacent literals, identifiers and subqueries are not framework enums.
        $n = count($tokens) - 1;
        for ($i = 3; $i < $n; $i++) {
            $offset = $i - 3;
            $expectsLiteral = $offset % 2 === 0;
            if ($expectsLiteral && $tokens[$i][0] !== 'literal') {
                return false;
            }
            if (!$expectsLiteral && $tokens[$i] !== ['punct', ',']) {
                return false;
            }
        }

        if (($n - 3) % 2 === 0) {
            return false;
        }

        return true;
    }

    /**
     * Rewrite the leading column identifier of a verified enum-shape CHECK.
     * Only call after isEnumCheckShape() returned true for $from.
     */
    public function rewriteEnumCheckColumn(string $expression, string $from, string $to): string
    {
        $quoted = '"' . str_replace('"', '""', $to) . '"';
        $pattern = '/^\s*(?:"' . preg_quote($from, '/') . '"|`' . preg_quote($from, '/') . '`|\['
            . preg_quote($from, '/') . '\]|' . preg_quote($from, '/') . ')/i';
        $result = preg_replace($pattern, $quoted, $expression, 1);
        if (!is_string($result)) {
            throw new \RuntimeException('Enum CHECK rewrite failed');
        }

        return $result;
    }

    /** Whole-word keyword present outside string literals and comments? */
    public function containsKeyword(string $sql, string $keyword): bool
    {
        $wanted = array_map('strtolower', preg_split('/\s+/', trim($keyword)) ?: []);
        $tokens = $this->tokenize($sql);
        $count = count($wanted);
        foreach (array_keys($tokens) as $i) {
            $matched = true;
            foreach ($wanted as $offset => $word) {
                if (($tokens[$i + $offset] ?? null) !== ['word', $word]) {
                    $matched = false;
                    break;
                }
            }
            if ($matched && $count > 0) {
                return true;
            }
        }

        return false;
    }

    public function containsUnquotedAsterisk(string $sql): bool
    {
        return in_array(['punct', '*'], $this->tokenize($sql), true);
    }

    /** Keyword present at parenthesis depth zero (i.e. table options / column list tail)? */
    public function hasKeywordOutsideParens(string $sql, string $keyword): bool
    {
        $depth = 0;
        $length = strlen($sql);
        $i = 0;
        $stripped = '';

        while ($i < $length) {
            $char = $sql[$i];
            if ($char === "'" || $char === '"' || $char === '`') {
                $i = $this->consumeQuoted($sql, $i, $char);
                continue;
            }
            if ($char === '[') {
                $close = strpos($sql, ']', $i + 1);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated bracket identifier in SQL');
                }
                $i = $close + 1;
                continue;
            }
            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline + 1;
                continue;
            }
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $close = strpos($sql, '*/', $i + 2);
                if ($close === false) {
                    throw new \RuntimeException('Unterminated block comment in SQL');
                }
                $i = $close + 2;
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth < 0) {
                    throw new \RuntimeException('Unbalanced parentheses in SQL');
                }
            } elseif ($depth === 0) {
                $stripped .= $char;
            }
            $i++;
        }

        return $this->containsKeyword($stripped, $keyword);
    }
}
