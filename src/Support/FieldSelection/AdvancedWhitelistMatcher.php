<?php

declare(strict_types=1);

namespace Glueful\Support\FieldSelection;

/**
 * Advanced whitelist pattern matcher supporting:
 * - Nested wildcards: posts.*, posts.*.comments
 * - Conditional whitelists: email:if(owner)
 * - Pattern exclusions: *,-password,-secret_key
 * - Dynamic context-based evaluation
 */
final class AdvancedWhitelistMatcher
{
    /** @var array<string> */
    private array $includePatterns = [];

    /** @var array<string> */
    private array $excludePatterns = [];

    /** @var array<string, string> */
    private array $conditionalPatterns = [];

    /**
     * @param array<string> $whitelist
     */
    public function __construct(array $whitelist = [])
    {
        $this->parseWhitelist($whitelist);
    }

    /**
     * Check if a field path is allowed
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $data
     */
    public function isAllowed(string $fieldPath, array $context = [], array $data = []): bool
    {
        // First check exclusions
        if ($this->isExcluded($fieldPath)) {
            return false;
        }

        // Check conditional patterns
        if ($this->hasConditionalRestriction($fieldPath, $context, $data)) {
            return false;
        }

        // Check include patterns
        return $this->isIncluded($fieldPath);
    }

    /**
     * Get all allowed field paths up to a given depth
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $data
     * @return array<string>
     */
    public function getAllowedPaths(array $context = [], array $data = [], int $maxDepth = 3): array
    {
        $allowed = [];

        foreach ($this->includePatterns as $pattern) {
            if ($pattern === '*') {
                // Generate common field paths
                $allowed = array_merge($allowed, $this->generateCommonPaths($maxDepth));
            } elseif (str_contains($pattern, '*')) {
                $allowed = array_merge($allowed, $this->expandWildcardPattern($pattern, $maxDepth));
            } else {
                $allowed[] = $pattern;
            }
        }

        // Filter by context and exclusions
        return array_filter(array_unique($allowed), function ($path) use ($context, $data) {
            return $this->isAllowed($path, $context, $data);
        });
    }

    /**
     * Parse whitelist patterns into include, exclude, and conditional lists
     *
     * @param array<string> $whitelist
     */
    private function parseWhitelist(array $whitelist): void
    {
        foreach ($whitelist as $pattern) {
            $pattern = trim($pattern);

            if ($pattern === '') {
                continue;
            }

            // Handle exclusions (patterns starting with -)
            if (str_starts_with($pattern, '-')) {
                $this->excludePatterns[] = substr($pattern, 1);
                continue;
            }

            // Handle conditional patterns (field:if(condition))
            if (preg_match('/^(.+):if\(([^)]+)\)$/', $pattern, $matches)) {
                $fieldPattern = $matches[1];
                $condition = $matches[2];
                $this->conditionalPatterns[$fieldPattern] = $condition;
                // Also add to include patterns so it can be included when condition is met
                $this->includePatterns[] = $fieldPattern;
                continue;
            }

            // Regular include pattern
            $this->includePatterns[] = $pattern;
        }

        // If no explicit include patterns, default to all (*)
        if ($this->includePatterns === []) {
            $this->includePatterns[] = '*';
        }
    }

    /**
     * Check if field path matches any exclusion pattern
     */
    private function isExcluded(string $fieldPath): bool
    {
        foreach ($this->excludePatterns as $pattern) {
            if ($this->matchesPattern($fieldPath, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if field path has conditional restriction that blocks access
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $data
     */
    private function hasConditionalRestriction(string $fieldPath, array $context, array $data): bool
    {
        $hasConditionalPattern = false;

        foreach ($this->conditionalPatterns as $pattern => $condition) {
            if ($this->matchesPattern($fieldPath, $pattern)) {
                $hasConditionalPattern = true;
                if ($this->evaluateCondition($condition, $context, $data)) {
                    return false; // Condition passed, field is allowed
                }
            }
        }

        // If field matches a conditional pattern but no conditions passed, restrict it
        return $hasConditionalPattern;
    }

    /**
     * Check if field path matches any include pattern
     */
    private function isIncluded(string $fieldPath): bool
    {
        foreach ($this->includePatterns as $pattern) {
            if ($this->matchesPattern($fieldPath, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if field path matches a pattern (supports wildcards)
     */
    private function matchesPattern(string $fieldPath, string $pattern): bool
    {
        // Exact match
        if ($fieldPath === $pattern) {
            return true;
        }

        // Full wildcard
        if ($pattern === '*') {
            return true;
        }

        // Convert pattern to regex
        $regex = $this->patternToRegex($pattern);
        return preg_match($regex, $fieldPath) === 1;
    }

    /**
     * Convert wildcard pattern to regex
     */
    private function patternToRegex(string $pattern): string
    {
        // Escape special regex characters except *
        $escaped = preg_quote($pattern, '/');

        // Replace escaped \* with regex wildcard
        $regex = str_replace('\*', '[^.]*', $escaped);

        // Handle specific patterns
        if (str_ends_with($pattern, '.*')) {
            // posts.* should match posts.title, posts.author, but not posts.comments.text
            $regex = str_replace('[^.]*', '[^.]+', $regex);
        }

        return '/^' . $regex . '$/';
    }

    /**
     * Evaluate a condition against context and data
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $data
     */
    private function evaluateCondition(string $condition, array $context, array $data): bool
    {
        switch ($condition) {
            case 'owner':
                return ($context['user']['id'] ?? null) === ($data['user_id'] ?? $data['owner_id'] ?? null);

            case 'admin':
                return ($context['user']['role'] ?? '') === 'admin';

            case 'authenticated':
                return isset($context['user']);

            case 'public':
                return ($data['is_public'] ?? false) === true;

            case 'premium':
                return ($context['user']['subscription'] ?? '') === 'premium';

            default:
                // Custom condition handlers could be registered here
                return true;
        }
    }

    /**
     * Generate common field paths for wildcard expansion
     *
     * @return array<string>
     */
    private function generateCommonPaths(int $maxDepth): array
    {
        $paths = ['id', 'name', 'email', 'created_at', 'updated_at'];

        if ($maxDepth >= 2) {
            $paths = array_merge($paths, [
                'user.id', 'user.name', 'user.email',
                'profile.avatar', 'profile.bio',
                'posts.id', 'posts.title', 'posts.content'
            ]);
        }

        if ($maxDepth >= 3) {
            $paths = array_merge($paths, [
                'posts.author.name', 'posts.comments.id', 'posts.comments.text',
                'user.profile.avatar', 'user.posts.title'
            ]);
        }

        return $paths;
    }

    /**
     * Expand wildcard patterns to specific paths
     *
     * @param int $maxDepth Maximum depth for expansion (currently unused but reserved for future enhancement)
     * @return array<string>
     */
    private function expandWildcardPattern(string $pattern, int $maxDepth): array
    {
        $paths = [];

        if ($pattern === 'posts.*') {
            $paths = ['posts.id', 'posts.title', 'posts.content', 'posts.author', 'posts.created_at'];
        } elseif ($pattern === 'user.*') {
            $paths = ['user.id', 'user.name', 'user.email', 'user.avatar'];
        } elseif ($pattern === '*.admin') {
            $paths = ['posts.admin', 'user.admin', 'profile.admin'];
        } elseif (preg_match('/^(.+)\.\*\.(.+)$/', $pattern, $matches)) {
            // posts.*.comments -> posts.recent.comments, posts.popular.comments
            $prefix = $matches[1];
            $suffix = $matches[2];
            $paths = [
                "{$prefix}.recent.{$suffix}",
                "{$prefix}.popular.{$suffix}",
                "{$prefix}.featured.{$suffix}"
            ];
        }

        return $paths;
    }
}
