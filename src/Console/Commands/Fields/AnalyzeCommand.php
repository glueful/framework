<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Fields;

use Glueful\Console\BaseCommand;
use Glueful\Support\FieldSelection\Performance\FieldSelectionMetrics;
use Glueful\Routing\Router;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Field Analysis Command
 * Analyzes field selection usage patterns across the application
 */
#[AsCommand(
    name: 'fields:analyze',
    description: 'Show field usage statistics and patterns'
)]
class AnalyzeCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Analyze field selection usage patterns across the application')
            ->setHelp('This command analyzes field selection patterns, identifies common usage, and provides insights.')
            ->addOption(
                'detailed',
                'd',
                InputOption::VALUE_NONE,
                'Show detailed analysis including route-by-route breakdown'
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format (table, json, csv)',
                'table'
            )
            ->addOption(
                'routes',
                'r',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Analyze specific routes (can be used multiple times)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $detailed = (bool) $input->getOption('detailed');
        $format = (string) $input->getOption('format');
        $specificRoutes = (array) $input->getOption('routes');

        $this->info('🔍 Analyzing field selection usage patterns...');

        try {
            // Get metrics and router
            $metrics = $this->getService(FieldSelectionMetrics::class);
            $router = $this->getService(Router::class);

            // Analyze usage patterns
            $analysis = $this->analyzeFieldUsage($metrics, $router, $specificRoutes);

            // Output results based on format
            match ($format) {
                'json' => $this->outputJson($analysis),
                'csv' => $this->outputCsv($analysis),
                default => $this->outputTable($analysis, $detailed)
            };

            $this->displayRecommendations($analysis);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Analysis failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param array<string> $specificRoutes
     * @return array<string,mixed>
     */
    private function analyzeFieldUsage(
        FieldSelectionMetrics $metrics,
        Router $router,
        array $specificRoutes
    ): array {
        $summary = $metrics->getSummary();
        $routes = [];
        // Get all Route objects from both static and dynamic routes
        foreach ($router->getStaticRoutes() as $route) {
            $routes[] = $route;
        }
        foreach ($router->getDynamicRoutes() as $methodRoutes) {
            foreach ($methodRoutes as $route) {
                $routes[] = $route;
            }
        }

        // Basic statistics
        $totalParsingOps = $summary['operations']['field_parsing']['count'] ?? 0;
        $totalProjectionOps = $summary['operations']['projection']['count'] ?? 0;
        $avgParsingTime = $summary['operations']['field_parsing']['avg_time_ms'] ?? 0;
        $avgProjectionTime = $summary['operations']['projection']['avg_time_ms'] ?? 0;

        // Analyze field patterns
        $fieldPatterns = $this->analyzeFieldPatterns($metrics);
        $routeAnalysis = $this->analyzeRoutes($routes, $specificRoutes);
        $performanceIssues = $this->identifyPerformanceIssues($metrics);

        return [
            'summary' => [
                'total_parsing_operations' => $totalParsingOps,
                'total_projection_operations' => $totalProjectionOps,
                'avg_parsing_time_ms' => round($avgParsingTime, 2),
                'avg_projection_time_ms' => round($avgProjectionTime, 2),
                'total_routes_analyzed' => count($routeAnalysis),
                'routes_with_field_selection' => count(
                    array_filter($routeAnalysis, fn($r) => $r['has_field_selection'] === true)
                ),
            ],
            'field_patterns' => $fieldPatterns,
            'routes' => $routeAnalysis,
            'performance' => $performanceIssues,
            'cache_stats' => $summary['cache'] ?? [],
            'recommendations' => $metrics->getRecommendations()
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function analyzeFieldPatterns(FieldSelectionMetrics $metrics): array
    {
        $parsingMetrics = $metrics->getOperationMetrics('field_parsing');
        $slowPatterns = $metrics->getOperationMetrics('slow_patterns');

        $patterns = [];
        $fieldCounts = [];
        $complexityLevels = ['simple' => 0, 'moderate' => 0, 'complex' => 0];

        foreach ($parsingMetrics as $metric) {
            $fieldCount = $metric['field_count'] ?? 0;
            $fieldCounts[] = $fieldCount;

            // Categorize complexity
            if ($fieldCount <= 5) {
                $complexityLevels['simple']++;
            } elseif ($fieldCount <= 15) {
                $complexityLevels['moderate']++;
            } else {
                $complexityLevels['complex']++;
            }
        }

        return [
            'total_patterns' => count($parsingMetrics),
            'avg_fields_per_request' => count($fieldCounts) > 0 ?
                round(array_sum($fieldCounts) / count($fieldCounts), 1) : 0,
            'max_fields_used' => count($fieldCounts) > 0 ? max($fieldCounts) : 0,
            'min_fields_used' => count($fieldCounts) > 0 ? min($fieldCounts) : 0,
            'complexity_distribution' => $complexityLevels,
            'slow_patterns_count' => count($slowPatterns),
            'most_common_field_count' => count($fieldCounts) > 0 ? $this->getMostCommon($fieldCounts) : 0
        ];
    }

    /**
     * @param array<\Glueful\Routing\Route> $routes
     * @param array<string> $specificRoutes
     * @return array<array<string,mixed>>
     */
    private function analyzeRoutes(array $routes, array $specificRoutes): array
    {
        $analysis = [];

        foreach ($routes as $route) {
            $routePath = $route->getPath();
            $routeName = $route->getName() ?? $routePath;

            // Skip if specific routes requested and this isn't one of them
            if (
                count($specificRoutes) > 0 &&
                !in_array($routeName, $specificRoutes, true) &&
                !in_array($routePath, $specificRoutes, true)
            ) {
                continue;
            }

            $hasFieldSelection = $this->routeHasFieldSelection($route);
            $whitelistConfig = $this->getRouteWhitelistConfig($route);

            $analysis[] = [
                'name' => $routeName,
                'path' => $routePath,
                'method' => $route->getMethod(),
                'has_field_selection' => $hasFieldSelection,
                'whitelist_configured' => count($whitelistConfig) > 0,
                'whitelist_fields' => count($whitelistConfig),
                'controller' => is_string($route->getHandler()) ? $route->getHandler() : 'Closure',
                'middleware' => $route->getMiddleware()
            ];
        }

        return $analysis;
    }

    /**
     * @return array<string,mixed>
     */
    private function identifyPerformanceIssues(FieldSelectionMetrics $metrics): array
    {
        $summary = $metrics->getSummary();
        $issues = [];

        // Check parsing performance
        if (isset($summary['operations']['field_parsing']['avg_time_ms'])) {
            $avgTime = $summary['operations']['field_parsing']['avg_time_ms'];
            if ($avgTime > 50) {
                $issues[] = [
                    'type' => 'slow_parsing',
                    'severity' => $avgTime > 100 ? 'high' : 'medium',
                    'message' => "Field parsing is slow (avg {$avgTime}ms)",
                    'suggestion' => 'Consider enabling field tree caching'
                ];
            }
        }

        // Check projection performance
        if (isset($summary['operations']['projection']['avg_time_ms'])) {
            $avgTime = $summary['operations']['projection']['avg_time_ms'];
            if ($avgTime > 100) {
                $issues[] = [
                    'type' => 'slow_projection',
                    'severity' => $avgTime > 200 ? 'high' : 'medium',
                    'message' => "Field projection is slow (avg {$avgTime}ms)",
                    'suggestion' => 'Consider optimizing field selection patterns or adding expanders'
                ];
            }
        }

        // Check cache efficiency
        if (isset($summary['cache']['hit_rate'])) {
            $hitRate = $summary['cache']['hit_rate'];
            if ($hitRate < 60) {
                $issues[] = [
                    'type' => 'low_cache_hit_rate',
                    'severity' => $hitRate < 30 ? 'high' : 'medium',
                    'message' => "Low cache hit rate ({$hitRate}%)",
                    'suggestion' => 'Review cache TTL settings or field selection patterns'
                ];
            }
        }

        // Check N+1 queries
        $n1Detected = $summary['counters']['n1_queries_detected'] ?? 0;
        $n1Prevented = $summary['counters']['n1_queries_prevented'] ?? 0;
        if ($n1Detected > $n1Prevented) {
            $unhandled = $n1Detected - $n1Prevented;
            $issues[] = [
                'type' => 'n1_queries',
                'severity' => 'high',
                'message' => "{$unhandled} unhandled N+1 queries detected",
                'suggestion' => 'Add expanders for detected relations'
            ];
        }

        return [
            'total_issues' => count($issues),
            'issues' => $issues,
            'overall_health' => $this->calculateHealthScore($issues)
        ];
    }

    private function routeHasFieldSelection(\Glueful\Routing\Route $route): bool
    {
        // Check if route has field selection middleware
        $middleware = $route->getMiddleware();
        return in_array('field_selection', $middleware, true) ||
               in_array('Glueful\Routing\Middleware\FieldSelectionMiddleware', $middleware, true);
    }

    /**
     * @return array<string>
     */
    private function getRouteWhitelistConfig(\Glueful\Routing\Route $route): array
    {
        // In a real implementation, this would check route attributes or configuration
        // For now, return empty array as placeholder
        return [];
    }

    /**
     * @param array<int> $values
     */
    private function getMostCommon(array $values): int
    {
        if (count($values) === 0) {
            return 0;
        }

        $counts = array_count_values($values);
        arsort($counts);
        return array_key_first($counts) ?? 0;
    }

    /**
     * @param array<array<string,mixed>> $issues
     */
    private function calculateHealthScore(array $issues): string
    {
        if (count($issues) === 0) {
            return 'excellent';
        }

        $highSeverityCount = count(array_filter($issues, fn($i) => $i['severity'] === 'high'));
        $mediumSeverityCount = count(array_filter($issues, fn($i) => $i['severity'] === 'medium'));

        if ($highSeverityCount > 0) {
            return 'poor';
        }

        if ($mediumSeverityCount > 2) {
            return 'fair';
        }

        return $mediumSeverityCount > 0 ? 'good' : 'excellent';
    }

    /**
     * @param array<string,mixed> $analysis
     */
    private function outputTable(array $analysis, bool $detailed): void
    {
        $summary = $analysis['summary'];

        $this->line('');
        $this->info('📊 Field Selection Usage Summary');
        $this->line('');

        // Summary table
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Parsing Operations', $summary['total_parsing_operations']],
                ['Total Projection Operations', $summary['total_projection_operations']],
                ['Avg Parsing Time', $summary['avg_parsing_time_ms'] . 'ms'],
                ['Avg Projection Time', $summary['avg_projection_time_ms'] . 'ms'],
                ['Routes Analyzed', $summary['total_routes_analyzed']],
                ['Routes with Field Selection', $summary['routes_with_field_selection']],
            ]
        );

        // Field patterns
        $patterns = $analysis['field_patterns'];
        if ($patterns['total_patterns'] > 0) {
            $this->line('');
            $this->info('🎯 Field Selection Patterns');
            $this->line('');

            $this->table(
                ['Pattern Metric', 'Value'],
                [
                    ['Total Patterns', $patterns['total_patterns']],
                    ['Avg Fields per Request', $patterns['avg_fields_per_request']],
                    ['Max Fields Used', $patterns['max_fields_used']],
                    ['Min Fields Used', $patterns['min_fields_used']],
                    ['Most Common Field Count', $patterns['most_common_field_count']],
                    ['Simple Patterns (≤5 fields)', $patterns['complexity_distribution']['simple']],
                    ['Moderate Patterns (6-15 fields)', $patterns['complexity_distribution']['moderate']],
                    ['Complex Patterns (>15 fields)', $patterns['complexity_distribution']['complex']],
                ]
            );
        }

        // Performance issues
        $performance = $analysis['performance'];
        if ($performance['total_issues'] > 0) {
            $this->line('');
            $this->warning('⚠️  Performance Issues Detected');
            $this->line('');

            $issueRows = [];
            foreach ($performance['issues'] as $issue) {
                $severityIcon = match ($issue['severity']) {
                    'high' => '🔴',
                    'medium' => '🟡',
                    default => '🟢'
                };

                $issueRows[] = [
                    $severityIcon . ' ' . ucfirst($issue['severity']),
                    $issue['message'],
                    $issue['suggestion']
                ];
            }

            $this->table(['Severity', 'Issue', 'Suggestion'], $issueRows);
            $this->line('Overall Health: ' . ucfirst($performance['overall_health']));
        }

        // Detailed route analysis
        if ($detailed && count($analysis['routes']) > 0) {
            $this->line('');
            $this->info('🛣️  Route Analysis');
            $this->line('');

            $routeRows = [];
            foreach ($analysis['routes'] as $route) {
                $routeRows[] = [
                    $route['name'],
                    $route['method'],
                    ($route['has_field_selection'] === true) ? '✅' : '❌',
                    ($route['whitelist_configured'] === true) ?
                        '✅ (' . $route['whitelist_fields'] . ')' : '❌',
                    $route['controller']
                ];
            }

            $this->table(
                ['Route', 'Method', 'Field Selection', 'Whitelist', 'Controller'],
                $routeRows
            );
        }
    }

    /**
     * @param array<string,mixed> $analysis
     */
    private function outputJson(array $analysis): void
    {
        $this->line(json_encode($analysis, JSON_PRETTY_PRINT));
    }

    /**
     * @param array<string,mixed> $analysis
     */
    private function outputCsv(array $analysis): void
    {
        // Output CSV headers and data for routes
        $this->line('route_name,method,has_field_selection,whitelist_configured,whitelist_fields,controller');

        foreach ($analysis['routes'] as $route) {
            $this->line(sprintf(
                '"%s","%s","%s","%s",%d,"%s"',
                $route['name'],
                $route['method'],
                ($route['has_field_selection'] === true) ? 'yes' : 'no',
                ($route['whitelist_configured'] === true) ? 'yes' : 'no',
                $route['whitelist_fields'],
                $route['controller']
            ));
        }
    }

    /**
     * @param array<string,mixed> $analysis
     */
    private function displayRecommendations(array $analysis): void
    {
        $recommendations = $analysis['recommendations'];

        if ($recommendations !== []) {
            $this->line('');
            $this->info('💡 Recommendations');
            $this->line('');

            foreach ($recommendations as $i => $recommendation) {
                $this->line(sprintf('%d. %s', $i + 1, $recommendation));
            }
        } else {
            $this->line('');
            $this->success('✅ No performance issues detected. Field selection is optimally configured!');
        }
    }
}
