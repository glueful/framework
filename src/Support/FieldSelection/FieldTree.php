<?php

declare(strict_types=1);

namespace Glueful\Support\FieldSelection;

/**
 * Root wrapper around a map of top-level FieldNodes.
 */
final class FieldTree
{
    /** @param array<string, FieldNode> $roots */
    private function __construct(private array $roots)
    {
    }

    /** @param array<string, FieldNode> $roots */
    public static function fromRoots(array $roots): self
    {
        return new self($roots);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->roots === [];
    }

    /** @return array<string, FieldNode> */
    public function roots(): array
    {
        return $this->roots;
    }

    public function has(string $name): bool
    {
        return isset($this->roots[$name]);
    }

    public function get(string $name): ?FieldNode
    {
        return $this->roots[$name] ?? null;
    }

    /**
     * Quick helper for controllers/services: dot-path presence.
     * Example: requested('posts.comments.text')
     */
    public function requested(string $dotPath): bool
    {
        $parts = array_values(array_filter(explode('.', $dotPath), fn ($p) => $p !== ''));
        if ($parts === []) {
            return false;
        }

        $node = $this->get(array_shift($parts));
        foreach ($parts as $p) {
            if ($node === null || !$node->has($p)) {
                return false;
            }
            $node = $node->child($p);
        }
        return $node !== null;
    }

    /**
     * Apply a whitelist to filter allowed fields
     *
     * @param array<string> $whitelist
     */
    public function applyWhitelist(array $whitelist): self
    {
        if ($whitelist === []) {
            return $this;
        }

        $filteredRoots = [];

        // Handle wildcard expansion
        if (isset($this->roots['*'])) {
            // If wildcard is present, expand to whitelisted fields
            foreach ($whitelist as $field) {
                if (str_contains($field, '.')) {
                    // Handle nested paths like 'posts.title'
                    $parts = explode('.', $field, 2);
                    $rootField = $parts[0];
                    if (!isset($filteredRoots[$rootField])) {
                        $filteredRoots[$rootField] = $this->roots[$rootField] ?? new FieldNode($rootField);
                    }
                } else {
                    $filteredRoots[$field] = $this->roots[$field] ?? new FieldNode($field);
                }
            }
        } else {
            // Filter existing roots against whitelist
            foreach ($this->roots as $name => $node) {
                if (in_array($name, $whitelist, true)) {
                    $filteredRoots[$name] = $node;
                    continue;
                }

                // Check for wildcard patterns like 'posts.*'
                foreach ($whitelist as $pattern) {
                    if (str_ends_with($pattern, '.*')) {
                        $prefix = rtrim($pattern, '.*');
                        if ($name === $prefix) {
                            $filteredRoots[$name] = $node;
                            break;
                        }
                    }
                }
            }
        }

        return new self($filteredRoots);
    }

    /**
     * Apply advanced whitelist patterns with context-aware filtering
     *
     * @param array<string> $whitelist
     * @param array<string,mixed> $context
     * @param array<string,mixed> $data
     */
    public function applyAdvancedWhitelist(array $whitelist, array $context = [], array $data = []): self
    {
        if ($whitelist === []) {
            return $this;
        }

        $matcher = new AdvancedWhitelistMatcher($whitelist);
        $filteredRoots = [];

        // Filter root fields
        foreach ($this->roots as $name => $node) {
            if ($matcher->isAllowed($name, $context, $data)) {
                $filteredNode = $this->filterNodeChildren($node, $name, $matcher, $context, $data);
                $filteredRoots[$name] = $filteredNode;
            }
        }

        // Handle wildcard expansion for allowed patterns
        if (isset($this->roots['*'])) {
            $allowedPaths = $matcher->getAllowedPaths($context, $data);
            foreach ($allowedPaths as $path) {
                if (str_contains($path, '.')) {
                    $parts = explode('.', $path, 2);
                    $rootField = $parts[0];
                    if (!isset($filteredRoots[$rootField])) {
                        $filteredRoots[$rootField] = new FieldNode($rootField);
                    }
                } else {
                    if (!isset($filteredRoots[$path])) {
                        $filteredRoots[$path] = new FieldNode($path);
                    }
                }
            }
        }

        return new self($filteredRoots);
    }

    /**
     * Recursively filter node children based on advanced patterns
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $data
     */
    private function filterNodeChildren(
        FieldNode $node,
        string $basePath,
        AdvancedWhitelistMatcher $matcher,
        array $context,
        array $data
    ): FieldNode {
        $filteredChildren = [];

        foreach ($node->children() as $childName => $childNode) {
            $childPath = $basePath . '.' . $childName;

            if ($matcher->isAllowed($childPath, $context, $data)) {
                $filteredChild = $this->filterNodeChildren($childNode, $childPath, $matcher, $context, $data);
                $filteredChildren[$childName] = $filteredChild;
            }
        }

        // Return a new node with filtered children
        return new FieldNode(
            $node->name,
            $filteredChildren,
            $node->alias,
            $node->transformation,
            $node->isComputed,
            $node->condition
        );
    }
}
