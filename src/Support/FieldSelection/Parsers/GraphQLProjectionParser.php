<?php

declare(strict_types=1);

namespace Glueful\Support\FieldSelection\Parsers;

use Glueful\Support\FieldSelection\{FieldNode, FieldTree};

final class GraphQLProjectionParser
{
    /**
     * Parse GraphQL-like selection:
     *   user(id,name,posts(id,title,comments(id,text)))
     *   or: (id,name,posts(title))
     * We accept either shape in ?fields=...
     */
    public function parse(?string $graph): FieldTree
    {
        if ($graph === null || $graph === '') {
            return FieldTree::empty();
        }

        $tokens = $this->tokenize($graph);
        $i = 0;

        // accept optional root alias "user(...)" OR "(...)" as anonymous root
        $roots = [];
        while ($i < \count($tokens)) {
            $name = $tokens[$i];

            if ($name === '(') {
                $nodes = $this->parseGroup($tokens, $i);
                foreach ($nodes as $n) {
                    $roots[$n->name] = $n;
                }
                continue;
            }

            // named root like user(...)
            $i++;
            if ($i >= \count($tokens) || $tokens[$i] !== '(') {
                // bare token, treat as scalar root
                $roots[$name] = new FieldNode($name);
                continue;
            }
            $i++; // consume '('
            $children = $this->parseInside($tokens, $i); // stops at ')'
            $roots[$name] = new FieldNode($name, $children);
            $i++; // consume ')'
            if ($i < \count($tokens) && $tokens[$i] === ',') {
                $i++;
            }
        }

        return FieldTree::fromRoots($roots);
    }

    /** @return string[] */
    private function tokenize(string $s): array
    {
        $s = trim($s);
        $buf = '';
        $out = [];
        $flush = function () use (&$buf, &$out) {
            $t = trim($buf);
            if ($t !== '') {
                $out[] = $t;
            }
            $buf = '';
        };
        for ($i = 0, $n = \strlen($s); $i < $n; $i++) {
            $ch = $s[$i];
            if ($ch === '(' || $ch === ')' || $ch === ',') {
                $flush();
                $out[] = $ch;
            } else {
                $buf .= $ch;
            }
        }
        $flush();
        return $out;
    }

    /**
     * @param string[] $t
     * @return array<string, FieldNode>
     */
    private function parseInside(array $t, int &$i): array
    {
        $children = [];
        while ($i < \count($t) && $t[$i] !== ')') {
            $name = $t[$i++];
            if ($i < \count($t) && $t[$i] === '(') {
                $i++; // '('
                $grand = $this->parseInside($t, $i);
                $children[$name] = new FieldNode($name, $grand);
                $i++; // ')'
            } else {
                $children[$name] = new FieldNode($name);
            }
            if ($i < \count($t) && $t[$i] === ',') {
                $i++;
            }
        }
        return $children;
    }

    /**
     * @param string[] $t
     * @return FieldNode[]
     */
    private function parseGroup(array $t, int &$i): array
    {
        // assumes current token is '('
        $i++;
        $children = $this->parseInside($t, $i); // up to ')'
        // flatten group into root nodes
        $i++; // consume ')'
        if ($i < \count($t) && $t[$i] === ',') {
            $i++;
        }
        return array_values($children);
    }
}
