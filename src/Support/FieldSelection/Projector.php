<?php

declare(strict_types=1);

namespace Glueful\Support\FieldSelection;

use Glueful\Support\FieldSelection\Exceptions\InvalidFieldSelectionException;
use Glueful\Support\FieldSelection\Performance\FieldSelectionMetrics;

/**
 * Applies a FieldTree to arrays/objects and runs expanders for relations.
 * Supports advanced features: aliases, transformations, computed fields, and conditions.
 * Expanders are callables registered per relation name: fn(array $rows, FieldNode $node): array
 */
final class Projector
{
    /** @var array<string, callable> relationName => expander */
    private array $expanders = [];

    /** @var array<string, callable> transformation function name => handler */
    private array $transformations = [];

    /** @var array<string, callable> computed field name => handler */
    private array $computedFields = [];

    private FieldSelectionMetrics $metrics;

    /** @param array<string,string[]> $whitelist */
    public function __construct(
        private readonly array $whitelist = [],
        private readonly bool $strictDefault = false,
        private readonly int $maxDepthDefault = 6,
        private readonly int $maxFieldsDefault = 200,
        private readonly int $maxItemsDefault = 1000,
        ?FieldSelectionMetrics $metrics = null
    ) {
        $this->metrics = $metrics ?? FieldSelectionMetrics::getInstance();
    }

    /** Register or override a relation expander */
    public function register(string $relation, callable $expander): void
    {
        $this->expanders[$relation] = $expander;
    }

    /** Register a transformation handler */
    public function registerTransformation(string $function, callable $handler): void
    {
        $this->transformations[$function] = $handler;
    }

    /** Register a computed field handler */
    public function registerComputedField(string $fieldName, callable $handler): void
    {
        $this->computedFields[$fieldName] = $handler;
    }

    /**
     * Project a record (or list of records).
     * $data can be an array (assoc), an object (ArrayAccess/getter), or list<array>.
     *
     * @param array<int,mixed>|array<string,mixed>|object $data
     * @param array<string>|null $allowed overrides whitelist key if given
     * @param array<string,mixed> $context context data for expanders
     * @return mixed
     */
    public function project(
        mixed $data,
        FieldSelector $selector,
        ?array $allowed = null,
        ?string $whitelistKey = null,
        array $context = []
    ): mixed {
        // Start performance monitoring
        $this->metrics->startTimer('projection');
        $startMemory = memory_get_usage(true);

        // Count items and fields for metrics
        $itemCount = $this->countItems($data);
        $fieldCount = count($selector->tree->roots());

        // Resolve whitelist
        $allowedFields = $allowed;
        if ($allowedFields === null && $whitelistKey !== null && isset($this->whitelist[$whitelistKey])) {
            $allowedFields = $this->whitelist[$whitelistKey];
        }

        // If empty selection, return as-is (fast-path).
        if ($selector->empty()) {
            $duration = $this->metrics->endTimer('projection');
            $memoryUsed = memory_get_usage(true) - $startMemory;
            $this->metrics->recordProjectionMetrics($itemCount, 0, $duration, $memoryUsed, ['fast_path' => true]);
            return $data;
        }

        // Expand '*' into allowed list when present.
        $tree = $this->expandWildcards($selector->tree, $allowedFields);

        // Guards
        $this->guardTree($tree, $selector, $allowedFields);

        // Detect potential N+1 queries
        $this->detectN1Queries($tree, $data);

        // Project lists vs single
        $result = null;
        if (\is_array($data) && $this->isList($data)) {
            $limit = min(\count($data), $selector->maxItems);
            $out = [];
            for ($i = 0; $i < $limit; $i++) {
                $out[] = $this->projectOne($data[$i], $tree, $context);
            }
            $result = $out;
        } else {
            $result = $this->projectOne($data, $tree, $context);
        }

        // End performance monitoring
        $duration = $this->metrics->endTimer('projection');
        $memoryUsed = memory_get_usage(true) - $startMemory;

        $this->metrics->recordProjectionMetrics($itemCount, $fieldCount, $duration, $memoryUsed, [
            'whitelist_used' => $allowedFields !== null,
            'whitelist_size' => $allowedFields !== null ? count($allowedFields) : 0,
            'tree_depth' => $this->calculateTreeDepth($tree),
            'is_list' => \is_array($data) && $this->isList($data)
        ]);

        return $result;
    }

    /**
     * @param array<string, FieldNode>|FieldTree $tree
     * @param array<string,mixed> $context
     */
    private function projectOne(mixed $row, FieldTree|array $tree, array $context = []): mixed
    {
        $roots = $tree instanceof FieldTree ? $tree->roots() : $tree;
        // normalize object to array-like
        $asArray = $this->toArray($row);

        $result = [];
        foreach ($roots as $name => $node) {
            if ($name === '*') {
                // '*' already expanded earlier; skip here.
                continue;
            }

            // Check if field should be included based on condition
            if ($node->hasCondition() && !$this->evaluateCondition($node->condition, $context, $asArray)) {
                continue;
            }

            // Handle computed fields
            if ($node->isComputed) {
                $value = $this->computeField($name, $node, $context, $asArray);
                $result[$node->outputName()] = $this->applyTransformation($value, $node);
                continue;
            }

            if (\array_key_exists($name, $asArray)) {
                $value = $asArray[$name];
                if ($node->children() !== []) {
                    // nested object / relation
                    if (\is_array($value) && $this->isList($value)) {
                        $projected = [];
                        foreach ($value as $child) {
                            $projected[] = $this->projectOne($child, $node->children(), $context);
                        }
                        $result[$node->outputName()] = $projected;
                    } else {
                        $result[$node->outputName()] = $this->projectOne($value, $node->children(), $context);
                    }
                } else {
                    // Apply transformation if specified
                    $result[$node->outputName()] = $this->applyTransformation($value, $node);
                }
                continue;
            }

            // Relation not in current row — try registered expander
            if (isset($this->expanders[$name])) {
                /** @var callable $exp */
                $exp = $this->expanders[$name];
                // Pass context to expander for intelligent batch loading
                $result[$node->outputName()] = $exp($context, $node, [$row]); // Enhanced signature with context first
                continue;
            }

            // Missing scalar is silently ignored (keeps BC).
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function toArray(mixed $row): array
    {
        if (\is_array($row)) {
            return $row;
        };
        if (\is_object($row)) {
            if (method_exists($row, 'toArray')) {
                return $row->toArray();
            };
            return get_object_vars($row);
        }
        return (array)$row;
    }

    /** @param array<int,mixed> $arr */
    private function isList(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    /** @param array<string>|null $allowed */
    private function expandWildcards(FieldTree $tree, ?array $allowed): FieldTree
    {
        if (!$tree->has('*')) {
            return $tree;
        }
        if ($allowed === null) {
            return $tree; // no whitelist, keep literal '*'
        }

        $roots = $tree->roots();
        unset($roots['*']);
        foreach ($allowed as $name) {
            if (!isset($roots[$name])) {
                $roots[$name] = new FieldNode($name);
            }
        }
        return FieldTree::fromRoots($roots);
    }

    /** @param array<string>|null $allowed */
    private function guardTree(FieldTree $tree, FieldSelector $selector, ?array $allowed): void
    {
        $maxDepth  = max(1, $selector->maxDepth ?? $this->maxDepthDefault);
        $maxFields = max(1, $selector->maxFields ?? $this->maxFieldsDefault);

        $count = 0;
        $walk = function (FieldNode $n, int $depth) use (&$count, $maxDepth, &$walk) {
            if ($depth > $maxDepth) {
                throw InvalidFieldSelectionException::depthExceeded($maxDepth);
            }
            $count++;
            foreach ($n->children() as $c) {
                $walk($c, $depth + 1);
            }
        };

        foreach ($tree->roots() as $node) {
            $walk($node, 1);
        }
        if ($count > $maxFields) {
            throw InvalidFieldSelectionException::tooManyFields($maxFields, $count);
        }

        if ($selector->strict) {
            if ($allowed === null) {
                return; // strict with no whitelist: cannot validate root names safely
            }
            $unknown = array_diff(array_keys($tree->roots()), $allowed);
            // allow '*' to be expanded earlier; if present literal at this point, treat it as unknown
            $unknown = array_values(array_filter($unknown, fn($k) => $k !== '*'));
            if ($unknown !== []) {
                throw InvalidFieldSelectionException::unknownFields($unknown, $allowed);
            }
        }
    }

    /**
     * Evaluate a conditional field based on context and data
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $data
     */
    private function evaluateCondition(?string $condition, array $context, array $data): bool
    {
        if ($condition === null) {
            return true;
        }

        // Simple condition evaluation - can be extended
        switch ($condition) {
            case 'public':
                return ($data['is_public'] ?? false) === true;
            case 'admin':
                return ($context['user']['role'] ?? '') === 'admin';
            case 'authenticated':
                return isset($context['user']);
            default:
                // Custom condition evaluation could be added here
                return true;
        }
    }

    /**
     * Compute a field value using registered handlers
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $data
     */
    private function computeField(string $fieldName, FieldNode $node, array $context, array $data): mixed
    {
        if (isset($this->computedFields[$fieldName])) {
            $handler = $this->computedFields[$fieldName];
            return $handler($context, $node, $data);
        }

        // Default computed field handlers
        switch ($fieldName) {
            case 'post_count':
                return count($data['posts'] ?? []);
            case 'last_login_ago':
                $lastLogin = $data['last_login_at'] ?? null;
                if ($lastLogin !== null) {
                    $date = new \DateTime($lastLogin);
                    return $date->diff(new \DateTime())->format('%a days ago');
                }
                return 'Never';
            default:
                return null;
        }
    }

    /**
     * Apply transformation to a field value
     */
    private function applyTransformation(mixed $value, FieldNode $node): mixed
    {
        if (!$node->hasTransformation()) {
            return $value;
        }

        $transformation = $node->getTransformation();
        if ($transformation === null) {
            return $value;
        }

        $function = $transformation['function'];
        $params = $transformation['params'];

        // Check for custom transformation handler
        if (isset($this->transformations[$function])) {
            $handler = $this->transformations[$function];
            return $handler($value, $params);
        }

        // Built-in transformations
        switch ($function) {
            case 'format':
                if ($value instanceof \DateTime) {
                    return $value->format($params[0] ?? 'Y-m-d');
                }
                if (is_string($value)) {
                    $date = new \DateTime($value);
                    return $date->format($params[0] ?? 'Y-m-d');
                }
                return $value;

            case 'currency':
                $currency = $params[0] ?? 'USD';
                if (is_numeric($value)) {
                    return number_format((float)$value, 2) . ' ' . $currency;
                }
                return $value;

            case 'uppercase':
                return is_string($value) ? strtoupper($value) : $value;

            case 'lowercase':
                return is_string($value) ? strtolower($value) : $value;

            case 'truncate':
                $length = (int)($params[0] ?? 100);
                if (is_string($value) && strlen($value) > $length) {
                    return substr($value, 0, $length) . '...';
                }
                return $value;

            default:
                return $value;
        }
    }

    /**
     * Count items in data for performance metrics
     */
    private function countItems(mixed $data): int
    {
        if (\is_array($data) && $this->isList($data)) {
            return count($data);
        }
        return 1;
    }

    /**
     * Calculate tree depth for performance analysis
     *
     * @param array<string, FieldNode>|FieldTree $tree
     */
    private function calculateTreeDepth(FieldTree|array $tree): int
    {
        $roots = $tree instanceof FieldTree ? $tree->roots() : $tree;
        $maxDepth = 0;

        foreach ($roots as $node) {
            $depth = $this->calculateNodeDepth($node, 1);
            $maxDepth = max($maxDepth, $depth);
        }

        return $maxDepth;
    }

    /**
     * Calculate depth of a single node
     */
    private function calculateNodeDepth(FieldNode $node, int $currentDepth): int
    {
        $children = $node->children();
        if ($children === []) {
            return $currentDepth;
        }

        $maxChildDepth = $currentDepth;
        foreach ($children as $child) {
            $childDepth = $this->calculateNodeDepth($child, $currentDepth + 1);
            $maxChildDepth = max($maxChildDepth, $childDepth);
        }

        return $maxChildDepth;
    }

    /**
     * Detect potential N+1 queries by analyzing field access patterns
     *
     * @param array<string, FieldNode>|FieldTree $tree
     */
    private function detectN1Queries(FieldTree|array $tree, mixed $data): void
    {
        $roots = $tree instanceof FieldTree ? $tree->roots() : $tree;

        // Only analyze lists that could cause N+1 queries
        if (!\is_array($data) || !$this->isList($data) || count($data) < 2) {
            return;
        }

        $itemCount = count($data);

        foreach ($roots as $fieldName => $node) {
            // Skip simple fields that won't cause N+1 queries
            if ($node->children() === []) {
                continue;
            }

            // Check if this field exists in the data (indicating a potential relation)
            $hasRelationData = false;
            foreach ($data as $item) {
                $asArray = $this->toArray($item);
                if (\array_key_exists($fieldName, $asArray)) {
                    $hasRelationData = true;
                    break;
                }
            }

            // If relation exists in data, no N+1 (data was pre-loaded)
            if ($hasRelationData) {
                $this->metrics->recordN1Detection($fieldName, 1, true);
                continue;
            }

            // Check if an expander exists for this relation
            if (isset($this->expanders[$fieldName])) {
                $this->metrics->recordN1Detection($fieldName, 1, true);
                continue;
            }

            // Potential N+1 query detected - each item would trigger a separate query
            $this->metrics->recordN1Detection($fieldName, $itemCount, false);
        }
    }
}
