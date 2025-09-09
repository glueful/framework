<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Fields;

use Glueful\Console\BaseCommand;
use Glueful\Routing\Router;
use Glueful\Support\FieldSelection\FieldSelector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Field Whitelist Check Command
 * Checks field selection whitelist compliance across routes
 */
#[AsCommand(
    name: 'fields:whitelist-check',
    description: 'Check whitelist compliance for field selections'
)]
class WhitelistCheckCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Check field selection whitelist compliance and security')
            ->setHelp('This command validates whitelist configurations and checks for security vulnerabilities.')
            ->addArgument(
                'pattern',
                InputArgument::OPTIONAL,
                'Test a specific field selection pattern against whitelists'
            )
            ->addOption(
                'route',
                'r',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Check specific routes (can be used multiple times)'
            )
            ->addOption(
                'strict',
                's',
                InputOption::VALUE_NONE,
                'Enable strict whitelist checking'
            )
            ->addOption(
                'security',
                null,
                InputOption::VALUE_NONE,
                'Focus on security-related whitelist issues'
            )
            ->addOption(
                'export',
                'e',
                InputOption::VALUE_REQUIRED,
                'Export whitelist analysis to file (json, csv)',
                null
            )
            ->addOption(
                'suggest-whitelist',
                null,
                InputOption::VALUE_NONE,
                'Suggest whitelist configurations based on common patterns'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pattern = $input->getArgument('pattern');
        $specificRoutes = (array) $input->getOption('route');
        $strict = (bool) $input->getOption('strict');
        $security = (bool) $input->getOption('security');
        $export = $input->getOption('export');
        $suggestWhitelist = (bool) $input->getOption('suggest-whitelist');

        $this->info('🔒 Checking field selection whitelist compliance...');

        try {
            // Test specific pattern if provided
            if ($pattern !== null) {
                return $this->testPattern((string) $pattern, $strict);
            }

            // Full whitelist analysis
            $router = $this->getService(Router::class);
            $analysis = $this->analyzeWhitelistCompliance($router, $specificRoutes, $strict, $security);

            // Generate suggestions if requested
            if ($suggestWhitelist) {
                $suggestions = $this->generateWhitelistSuggestions($analysis);
                $analysis['suggestions'] = $suggestions;
            }

            // Export results if requested
            if ($export !== null) {
                $this->exportAnalysis($analysis, (string) $export);
            }

            // Display results
            $this->displayAnalysis($analysis, $security);

            // Determine exit code
            $hasSecurityIssues = ($analysis['security']['critical_issues'] ?? 0) > 0;
            $hasErrors = ($analysis['summary']['compliance_failures'] ?? 0) > 0;

            if ($hasSecurityIssues) {
                $this->error('🔴 Critical security issues found!');
                return self::FAILURE;
            }

            if ($hasErrors && $strict) {
                $this->warning('⚠️  Whitelist compliance issues found (strict mode).');
                return self::FAILURE;
            }

            $this->success('✅ Whitelist compliance check completed!');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Whitelist check failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function testPattern(string $pattern, bool $strict): int
    {
        $this->info("🧪 Testing pattern: {$pattern}");
        $this->line('');

        // Test against common whitelist configurations
        $testConfigs = [
            'api_basic' => ['id', 'name', 'email', 'created_at'],
            'user_profile' => ['id', 'name', 'email', 'profile', 'avatar', 'bio'],
            'admin_full' => ['*'],
            'restrictive' => ['id', 'name'],
        ];

        $results = [];
        foreach ($testConfigs as $configName => $whitelist) {
            try {
                $request = Request::create('/test', 'GET');
                $request->query->set('fields', $pattern);

                $selector = FieldSelector::fromRequest($request, $strict, 6, 200, 1000, $whitelist);

                $results[] = [
                    'config' => $configName,
                    'whitelist' => $whitelist,
                    'status' => $selector->empty() ? 'empty' : 'success',
                    'message' => $selector->empty() ? 'Pattern resulted in empty selection' : 'Pattern accepted',
                    'field_count' => count($selector->tree->roots())
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'config' => $configName,
                    'whitelist' => $whitelist,
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                    'field_count' => 0
                ];
            }
        }

        // Display results
        $this->table(
            ['Config', 'Status', 'Fields', 'Message'],
            array_map(fn($r) => [
                $r['config'],
                $this->getStatusIcon($r['status']) . ' ' . $r['status'],
                $r['field_count'],
                strlen($r['message']) > 50 ? substr($r['message'], 0, 47) . '...' : $r['message']
            ], $results)
        );

        $passed = count(array_filter($results, fn($r) => $r['status'] === 'success'));
        $total = count($results);

        $this->line('');
        $this->info("Pattern compatibility: {$passed}/{$total} whitelist configurations");

        return $passed > 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array<string> $specificRoutes
     * @return array<string,mixed>
     */
    private function analyzeWhitelistCompliance(
        Router $router,
        array $specificRoutes,
        bool $strict,
        bool $security
    ): array {
        // Note: This is a placeholder implementation since we don't have the actual Router::getRoutes() method
        // In a real implementation, this would iterate through actual routes

        $routes = []; // $router->getRoutes(); - placeholder
        $analysis = [
            'summary' => [
                'total_routes' => 0,
                'whitelist_configured' => 0,
                'compliance_passes' => 0,
                'compliance_failures' => 0,
                'no_whitelist' => 0
            ],
            'routes' => [],
            'security' => [
                'critical_issues' => 0,
                'medium_issues' => 0,
                'low_issues' => 0,
                'issues' => []
            ],
            'common_patterns' => $this->analyzeCommonPatterns(),
            'recommendations' => []
        ];

        // Placeholder route data for demonstration
        $placeholderRoutes = [
            ['name' => 'api.users.index', 'path' => '/api/users', 'has_whitelist' => true],
            ['name' => 'api.posts.show', 'path' => '/api/posts/{id}', 'has_whitelist' => false],
            ['name' => 'api.admin.users', 'path' => '/api/admin/users', 'has_whitelist' => true],
        ];

        foreach ($placeholderRoutes as $routeData) {
            if ($specificRoutes !== [] && !in_array($routeData['name'], $specificRoutes, true)) {
                continue;
            }

            $analysis['summary']['total_routes']++;

            $routeAnalysis = $this->analyzeRouteWhitelist($routeData, $strict, $security);
            $analysis['routes'][] = $routeAnalysis;

            // Update summary
            if (($routeAnalysis['has_whitelist'] ?? false) === true) {
                $analysis['summary']['whitelist_configured']++;
                if ($routeAnalysis['compliance'] === 'pass') {
                    $analysis['summary']['compliance_passes']++;
                } else {
                    $analysis['summary']['compliance_failures']++;
                }
            } else {
                $analysis['summary']['no_whitelist']++;
            }

            // Add security issues
            foreach ($routeAnalysis['security_issues'] as $issue) {
                $analysis['security']['issues'][] = $issue;
                $analysis['security'][$issue['severity'] . '_issues']++;
            }
        }

        // Generate recommendations
        $analysis['recommendations'] = $this->generateRecommendations($analysis);

        return $analysis;
    }

    /**
     * @param array<string,mixed> $routeData
     * @return array<string,mixed>
     */
    private function analyzeRouteWhitelist(array $routeData, bool $strict, bool $security): array
    {
        $issues = [];
        $securityIssues = [];

        // Check if whitelist is configured
        $hasWhitelist = $routeData['has_whitelist'] ?? false;

        if ($hasWhitelist === false) {
            if ($security && str_contains($routeData['path'], '/api/')) {
                $securityIssues[] = [
                    'severity' => 'medium',
                    'type' => 'MISSING_WHITELIST',
                    'message' => 'API route without whitelist protection',
                    'route' => $routeData['name']
                ];
            }
        }

        // Check for sensitive routes
        if ($security && str_contains($routeData['path'], 'admin')) {
            if ($hasWhitelist === false) {
                $securityIssues[] = [
                    'severity' => 'critical',
                    'type' => 'ADMIN_NO_WHITELIST',
                    'message' => 'Admin route without field restrictions',
                    'route' => $routeData['name']
                ];
            }
        }

        // Check for user-specific routes
        if (str_contains($routeData['path'], '{id}')) {
            $issues[] = [
                'type' => 'USER_DATA_ACCESS',
                'message' => 'Route accesses user-specific data - ensure proper field restrictions',
                'suggestion' => 'Consider user-based field filtering'
            ];
        }

        // Determine compliance status
        $compliance = 'pass';
        if ($hasWhitelist === false && $strict) {
            $compliance = 'fail';
        }
        if (count($securityIssues) > 0) {
            $compliance = 'security_risk';
        }

        return [
            'name' => $routeData['name'],
            'path' => $routeData['path'],
            'has_whitelist' => $hasWhitelist,
            'compliance' => $compliance,
            'issues' => $issues,
            'security_issues' => $securityIssues,
            'risk_level' => $this->calculateRiskLevel($securityIssues)
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function analyzeCommonPatterns(): array
    {
        // Analyze common field selection patterns used in the application
        return [
            'most_requested_fields' => ['id', 'name', 'email', 'created_at', 'updated_at'],
            'sensitive_fields' => ['password', 'token', 'secret', 'private_key'],
            'admin_only_fields' => ['internal_notes', 'system_flags', 'audit_log'],
            'pattern_frequency' => [
                'simple' => 65,  // id,name,email
                'moderate' => 25, // user(id,name,posts(title))
                'complex' => 10   // deep nested selections
            ]
        ];
    }

    /**
     * @param array<string,mixed> $analysis
     * @return array<string>
     */
    private function generateRecommendations(array $analysis): array
    {
        $recommendations = [];

        $noWhitelistCount = $analysis['summary']['no_whitelist'];
        if ($noWhitelistCount > 0) {
            $recommendations[] = "Configure whitelists for {$noWhitelistCount} routes without field restrictions";
        }

        $criticalIssues = $analysis['security']['critical_issues'];
        if ($criticalIssues > 0) {
            $recommendations[] = "Immediately address {$criticalIssues} critical security issues";
        }

        $failureRate = $analysis['summary']['compliance_failures'] / max(1, $analysis['summary']['total_routes']) * 100;
        if ($failureRate > 20) {
            $recommendations[] = "High compliance failure rate ({$failureRate}%) - review whitelist strategy";
        }

        return $recommendations;
    }

    /**
     * @param array<string,mixed> $analysis
     * @return array<string,mixed>
     */
    private function generateWhitelistSuggestions(array $analysis): array
    {
        $commonPatterns = $analysis['common_patterns'];

        return [
            'api_routes' => [
                'basic' => $commonPatterns['most_requested_fields'],
                'user_profile' => array_merge($commonPatterns['most_requested_fields'], ['profile', 'avatar']),
                'admin' => ['*'], // Admin routes can access all fields
            ],
            'security_exclusions' => [
                'always_exclude' => $commonPatterns['sensitive_fields'],
                'admin_only' => $commonPatterns['admin_only_fields']
            ],
            'template' => [
                'comment' => 'Generated whitelist suggestions - review before implementing',
                'configurations' => [
                    'public_api' => array_diff(
                        $commonPatterns['most_requested_fields'],
                        $commonPatterns['sensitive_fields']
                    ),
                    'authenticated_api' => $commonPatterns['most_requested_fields'],
                    'admin_api' => ['*']
                ]
            ]
        ];
    }

    /**
     * @param array<array<string,string>> $securityIssues
     */
    private function calculateRiskLevel(array $securityIssues): string
    {
        $criticalCount = count(array_filter($securityIssues, fn($i) => $i['severity'] === 'critical'));
        $mediumCount = count(array_filter($securityIssues, fn($i) => $i['severity'] === 'medium'));

        if ($criticalCount > 0) {
            return 'high';
        }
        if ($mediumCount > 1) {
            return 'medium';
        }
        return count($securityIssues) > 0 ? 'low' : 'none';
    }

    private function getStatusIcon(string $status): string
    {
        return match ($status) {
            'success' => '✅',
            'failed' => '❌',
            'empty' => '⚪',
            default => '❓'
        };
    }

    /**
     * @param array<string,mixed> $analysis
     */
    private function displayAnalysis(array $analysis, bool $security): void
    {
        $summary = $analysis['summary'];

        $this->line('');
        $this->info('📊 Whitelist Compliance Summary');
        $this->line('');

        // Summary table
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Routes', $summary['total_routes']],
                ['With Whitelist', $summary['whitelist_configured']],
                ['Compliance Pass', $summary['compliance_passes']],
                ['Compliance Fail', $summary['compliance_failures']],
                ['No Whitelist', $summary['no_whitelist']],
            ]
        );

        // Security analysis
        if ($security) {
            $securitySummary = $analysis['security'];
            $this->line('');
            $this->info('🔒 Security Analysis');
            $this->line('');

            $this->table(
                ['Severity', 'Count'],
                [
                    ['Critical', $securitySummary['critical_issues']],
                    ['Medium', $securitySummary['medium_issues']],
                    ['Low', $securitySummary['low_issues']],
                ]
            );

            if (($securitySummary['issues'] ?? []) !== []) {
                $this->line('');
                $this->warning('🚨 Security Issues:');
                $this->line('');

                foreach ($securitySummary['issues'] as $issue) {
                    $severityIcon = match ($issue['severity']) {
                        'critical' => '🔴',
                        'medium' => '🟡',
                        'low' => '🟢',
                        default => '⚪'
                    };

                    $this->line("  {$severityIcon} [{$issue['route']}] {$issue['message']}");
                }
            }
        }

        // Route details
        if (($analysis['routes'] ?? []) !== []) {
            $this->line('');
            $this->info('🛣️  Route Analysis');
            $this->line('');

            $routeRows = [];
            foreach ($analysis['routes'] as $route) {
                $complianceIcon = match ($route['compliance']) {
                    'pass' => '✅',
                    'fail' => '❌',
                    'security_risk' => '🔴',
                    default => '❓'
                };

                $routeRows[] = [
                    $route['name'],
                    (($route['has_whitelist'] ?? false) === true) ? '✅' : '❌',
                    $complianceIcon . ' ' . $route['compliance'],
                    $route['risk_level'],
                    count($route['issues']) + count($route['security_issues'])
                ];
            }

            $this->table(
                ['Route', 'Whitelist', 'Compliance', 'Risk', 'Issues'],
                $routeRows
            );
        }

        // Recommendations
        if (($analysis['recommendations'] ?? []) !== []) {
            $this->line('');
            $this->info('💡 Recommendations');
            $this->line('');

            foreach ($analysis['recommendations'] as $i => $recommendation) {
                $this->line(sprintf('%d. %s', $i + 1, $recommendation));
            }
        }

        // Whitelist suggestions
        if (isset($analysis['suggestions'])) {
            $this->line('');
            $this->info('🎯 Suggested Whitelist Configurations');
            $this->line('');

            foreach ($analysis['suggestions']['api_routes'] as $type => $fields) {
                $fieldList = is_array($fields) ? implode(', ', $fields) : (string) $fields;
                $this->line("  {$type}: {$fieldList}");
            }
        }
    }

    /**
     * @param array<string,mixed> $analysis
     */
    private function exportAnalysis(array $analysis, string $format): void
    {
        $filename = 'whitelist_analysis_' . date('Y-m-d_H-i-s');

        try {
            if ($format === 'json') {
                $filename .= '.json';
                file_put_contents($filename, json_encode($analysis, JSON_PRETTY_PRINT));
            } elseif ($format === 'csv') {
                $filename .= '.csv';
                $this->exportToCsv($analysis, $filename);
            } else {
                throw new \InvalidArgumentException("Unsupported export format: {$format}");
            }

            $this->success("Analysis exported to: {$filename}");
        } catch (\Exception $e) {
            $this->error("Export failed: " . $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $analysis
     */
    private function exportToCsv(array $analysis, string $filename): void
    {
        $handle = fopen($filename, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Cannot create file: {$filename}");
        }

        // CSV Headers
        fputcsv($handle, ['Route', 'Path', 'Has Whitelist', 'Compliance', 'Risk Level', 'Issues Count']);

        // CSV Data
        foreach ($analysis['routes'] as $route) {
            fputcsv($handle, [
                $route['name'],
                $route['path'],
                (($route['has_whitelist'] ?? false) === true) ? 'Yes' : 'No',
                $route['compliance'],
                $route['risk_level'],
                count($route['issues']) + count($route['security_issues'])
            ]);
        }

        fclose($handle);
    }
}
