<?php

declare(strict_types=1);

namespace Glueful\Support\FieldSelection\Parsers;

use Glueful\Support\FieldSelection\{FieldNode, FieldTree};

final class RestProjectionParser
{
    /**
     * Parse REST style:
     *   ?fields=*,id,name,posts.title
     *   &expand=posts,posts.comments,profile
     * Also supports dotted child paths inside fields (treated as nested nodes).
     *
     * @param string|null $fieldsCsv
     * @param string|null $expandCsv
     */
    public function parse(?string $fieldsCsv, ?string $expandCsv): FieldTree
    {
        if ($fieldsCsv === null && $expandCsv === null) {
            return FieldTree::empty();
        }

        $root = [];
        $addPath = function (string $path) use (&$root): void {
            $parts = array_values(array_filter(explode('.', trim($path)), fn($p) => $p !== ''));
            if ($parts === []) {
                return;
            }

            $cursor =& $root;
            foreach ($parts as $i => $part) {
                if (!isset($cursor[$part])) {
                    $cursor[$part] = new FieldNode($part);
                }
                // dive by reference into children
                $children = $cursor[$part]->children();
                $cursor[$part] = new FieldNode($part, $children);
                $ref =& $cursor[$part]; // keep typed ref

                // swap the array reference to the children map
                $cursor =& $this->childrenRef($ref);
            }
        };

        foreach ([$fieldsCsv, $expandCsv] as $csv) {
            if ($csv === null) {
                continue;
            }
            foreach ($this->splitFields($csv) as $token) {
                $t = trim($token);
                if ($t === '') {
                    continue;
                }
                if ($t === '*') { // register wildcard marker at root
                    if (!isset($root['*'])) {
                        $root['*'] = new FieldNode('*');
                    }
                    continue;
                }
                $this->addAdvancedPath($t, $root);
            }
        }

        return FieldTree::fromRoots($root);
    }

    /**
     * Parse advanced field syntax and add to root tree
     * Supports:
     * - Aliases: name:full_name
     * - Computed fields: @post_count
     * - Transformations: created_at:format(Y-m-d)
     * - Conditional fields: email:if(public)
     *
     * @param array<string, FieldNode> $root
     */
    private function addAdvancedPath(string $token, array &$root): void
    {
        // Check for computed field (@field_name)
        if (str_starts_with($token, '@')) {
            $fieldName = substr($token, 1);
            $root[$fieldName] = new FieldNode($fieldName, [], null, null, true);
            return;
        }

        // Parse field:transformation or field:alias syntax
        if (str_contains($token, ':')) {
            $parts = explode(':', $token);
            $fieldPath = trim($parts[0]);

            $alias = null;
            $transformation = null;
            $condition = null;

            // Process directives from left to right
            for ($i = 1; $i < count($parts); $i++) {
                $directive = trim($parts[$i]);

                // Parse conditional fields: if(condition)
                if (preg_match('/^if\(([^)]+)\)$/', $directive, $matches)) {
                    $condition = $matches[1];
                    continue;
                }

                // Parse transformations: function(params)
                if (preg_match('/^(\w+)\(([^)]*)\)$/', $directive, $matches)) {
                    $transformation = $directive;
                    continue;
                }

                // Check if it's a known transformation function without params
                if (in_array($directive, ['uppercase', 'lowercase', 'reverse'], true)) {
                    $transformation = $directive;
                    continue;
                }

                // Otherwise, treat as alias (first non-function directive)
                if ($alias === null) {
                    $alias = $directive;
                }
            }

            $this->addPathWithOptions($fieldPath, $root, $alias, $transformation, false, $condition);
            return;
        }

        // Regular field path
        $this->addPathWithOptions($token, $root);
    }

    /**
     * Add a path with advanced options
     *
     * @param array<string, FieldNode> $root
     */
    private function addPathWithOptions(
        string $path,
        array &$root,
        ?string $alias = null,
        ?string $transformation = null,
        bool $isComputed = false,
        ?string $condition = null
    ): void {
        $parts = array_values(array_filter(explode('.', trim($path)), fn($p) => $p !== ''));
        if ($parts === []) {
            return;
        }

        $cursor =& $root;
        foreach ($parts as $i => $part) {
            $isLast = $i === count($parts) - 1;

            if (!isset($cursor[$part])) {
                // For the last part, use the advanced options
                if ($isLast) {
                    $cursor[$part] = new FieldNode($part, [], $alias, $transformation, $isComputed, $condition);
                } else {
                    $cursor[$part] = new FieldNode($part);
                }
            }

            // For intermediate nodes, dive into children
            if (!$isLast) {
                $children = $cursor[$part]->children();
                $cursor[$part] = new FieldNode($part, $children);
                $ref =& $cursor[$part];
                $cursor =& $this->childrenRef($ref);
            }
        }
    }

    /**
     * Split fields string respecting parentheses
     * @return array<string>
     */
    private function splitFields(string $csv): array
    {
        $fields = [];
        $current = '';
        $depth = 0;
        $length = strlen($csv);

        for ($i = 0; $i < $length; $i++) {
            $char = $csv[$i];

            if ($char === '(') {
                $depth++;
                $current .= $char;
            } elseif ($char === ')') {
                $depth--;
                $current .= $char;
            } elseif ($char === ',' && $depth === 0) {
                // Only split on commas outside parentheses
                if (trim($current) !== '') {
                    $fields[] = trim($current);
                }
                $current = '';
            } else {
                $current .= $char;
            }
        }

        // Add the final field
        if (trim($current) !== '') {
            $fields[] = trim($current);
        }

        return $fields;
    }

    /**
     * Trick to mutate nested children (immutability at boundary, mutable during build).
     * @return array<string, FieldNode>
     */
    private function &childrenRef(FieldNode $node): array
    {
        // we want to get a reference to the children array and assign back later
        $children = $node->children();
        $ref =& $children;
        // Save back on shutdown (PHP passes objects by handle, but FieldNode is immutable)
        // We just keep returning $ref; caller overwrites the node with a new one as needed.
        return $ref;
    }
}
