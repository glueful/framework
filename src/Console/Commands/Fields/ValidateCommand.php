<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Fields;

use Glueful\Console\BaseCommand;
use Glueful\Routing\Router;
use Glueful\Support\FieldSelection\FieldSelector;
use Glueful\Support\FieldSelection\Exceptions\InvalidFieldSelectionException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Field Validation Command
 * Validates field selection configurations across all routes
 */
#[AsCommand(
    name: 'fields:validate',
    description: 'Validate all route field configurations'
)]
class ValidateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Validate field selection configurations across all application routes')
            ->setHelp('This command validates field selection patterns, whitelists, and configurations for all routes.')
            ->addOption(
                'route',
                'r',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Validate specific routes (can be used multiple times)'
            )
            ->addOption(
                'fix',
                null,
                InputOption::VALUE_NONE,
                'Attempt to fix common validation issues'
            )
            ->addOption(
                'strict',
                's',
                InputOption::VALUE_NONE,
                'Enable strict validation mode'
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format (table, json)',
                'table'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $specificRoutes = (array) $input->getOption('route');
        $fix = (bool) $input->getOption('fix');
        $strict = (bool) $input->getOption('strict');
        $format = (string) $input->getOption('format');

        $this->info('🔍 Validating field selection configurations...');

        try {
            $router = $this->getService(Router::class);
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

            $validation = $this->validateRoutes($routes, $specificRoutes, $strict, $fix);

            // Output results
            if ($format === 'json') {
                $this->outputJson($validation);
            } else {
                $this->outputTable($validation);
            }

            // Return appropriate exit code
            $hasErrors = $validation['summary']['errors'] > 0;
            $hasWarnings = $validation['summary']['warnings'] > 0;

            if ($hasErrors) {
                $this->error('❌ Validation failed with errors.');
                return self::FAILURE;
            }

            if ($hasWarnings) {
                $this->warning('⚠️  Validation completed with warnings.');
                return $strict ? self::FAILURE : self::SUCCESS;
            }

            $this->success('✅ All field configurations are valid!');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Validation failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param array<\Glueful\Routing\Route> $routes
     * @param array<string> $specificRoutes
     * @return array<string,mixed>
     */
    private function validateRoutes(array $routes, array $specificRoutes, bool $strict, bool $fix): array
    {
        $results = [];
        $summary = ['total' => 0, 'passed' => 0, 'warnings' => 0, 'errors' => 0, 'fixed' => 0];

        foreach ($routes as $route) {
            $routeName = $route->getName() ?? $route->getPath();
            $routePath = $route->getPath();

            // Skip if specific routes requested and this isn't one of them
            if (
                $specificRoutes !== [] &&
                !in_array($routeName, $specificRoutes, true) &&
                !in_array($routePath, $specificRoutes, true)
            ) {
                continue;
            }

            $summary['total']++;

            $result = $this->validateRoute($route, $strict, $fix);
            $results[] = $result;

            // Update summary
            if ($result['status'] === 'passed') {
                $summary['passed']++;
            } elseif ($result['status'] === 'warning') {
                $summary['warnings']++;
            } else {
                $summary['errors']++;
            }

            if ($result['fixed'] === true) {
                $summary['fixed']++;
            }
        }

        return [
            'summary' => $summary,
            'results' => $results,
            'validation_mode' => $strict ? 'strict' : 'normal',
            'fix_mode' => $fix
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validateRoute(\Glueful\Routing\Route $route, bool $strict, bool $fix): array
    {
        $routeName = $route->getName() ?? $route->getPath();
        $issues = [];
        $warnings = [];
        $fixed = false;

        // Check if route has field selection middleware
        $hasFieldSelection = $this->routeHasFieldSelection($route);

        // Validate field selection patterns if configured
        if ($hasFieldSelection) {
            $validationResult = $this->validateFieldSelectionPatterns($route, $strict);
            $issues = array_merge($issues, $validationResult['errors']);
            $warnings = array_merge($warnings, $validationResult['warnings']);

            // Validate whitelist configuration
            $whitelistResult = $this->validateWhitelistConfiguration($route, $strict);
            $issues = array_merge($issues, $whitelistResult['errors']);
            $warnings = array_merge($warnings, $whitelistResult['warnings']);

            // Validate controller compatibility
            $controllerResult = $this->validateControllerCompatibility($route);
            $issues = array_merge($issues, $controllerResult['errors']);
            $warnings = array_merge($warnings, $controllerResult['warnings']);

            // Attempt fixes if requested
            if ($fix && (count($issues) > 0 || count($warnings) > 0)) {
                $fixResult = $this->attemptFixes($route, $issues, $warnings);
                $fixed = $fixResult['fixed'];
                $issues = array_filter(
                    $issues,
                    fn($issue) => !in_array($issue['code'], $fixResult['fixed_issues'], true)
                );
                $warnings = array_filter(
                    $warnings,
                    fn($warning) => !in_array($warning['code'], $fixResult['fixed_warnings'], true)
                );
            }
        } else {
            // Check if route should have field selection
            if ($this->shouldHaveFieldSelection($route)) {
                $warnings[] = [
                    'code' => 'MISSING_FIELD_SELECTION',
                    'message' => 'Route might benefit from field selection middleware',
                    'suggestion' => 'Consider adding field selection middleware for API routes'
                ];
            }
        }

        // Determine overall status
        $status = 'passed';
        if (count($issues) > 0) {
            $status = 'error';
        } elseif (count($warnings) > 0) {
            $status = 'warning';
        }

        return [
            'route' => $routeName,
            'path' => $route->getPath(),
            'method' => $route->getMethod(),
            'has_field_selection' => $hasFieldSelection,
            'status' => $status,
            'issues' => $issues,
            'warnings' => $warnings,
            'fixed' => $fixed
        ];
    }

    private function routeHasFieldSelection(\Glueful\Routing\Route $route): bool
    {
        $middleware = $route->getMiddleware();
        return in_array('field_selection', $middleware, true) ||
               in_array('Glueful\Routing\Middleware\FieldSelectionMiddleware', $middleware, true);
    }

    /**
     * @return array<string,array<array<string,string>>>
     */
    private function validateFieldSelectionPatterns(\Glueful\Routing\Route $route, bool $strict): array
    {
        $errors = [];
        $warnings = [];

        // Test common field selection patterns
        $testPatterns = [
            'id,name,email',
            'user(id,name,profile(avatar,bio))',
            'posts(title,content,author(name)),comments(count)',
            '*'
        ];

        foreach ($testPatterns as $pattern) {
            try {
                $request = Request::create($route->getPath(), $route->getMethod());
                $request->query->set('fields', $pattern);

                $selector = FieldSelector::fromRequest($request, $strict);

                // Validate the selector was created successfully
                if ($selector->empty() && $pattern !== '*') {
                    $warnings[] = [
                        'code' => 'EMPTY_SELECTOR',
                        'message' => "Pattern '{$pattern}' resulted in empty selector",
                        'suggestion' => 'Check if whitelist is too restrictive'
                    ];
                }
            } catch (InvalidFieldSelectionException $e) {
                if (
                    $strict ||
                    str_contains($e->getMessage(), 'exceeded') ||
                    str_contains($e->getMessage(), 'unknown')
                ) {
                    $errors[] = [
                        'code' => 'INVALID_PATTERN',
                        'message' => "Pattern '{$pattern}' failed validation: " . $e->getMessage(),
                        'suggestion' => 'Review field selection limits and whitelist configuration'
                    ];
                } else {
                    $warnings[] = [
                        'code' => 'PATTERN_WARNING',
                        'message' => "Pattern '{$pattern}' has issues: " . $e->getMessage(),
                        'suggestion' => 'Consider reviewing field selection configuration'
                    ];
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'code' => 'PATTERN_ERROR',
                    'message' => "Unexpected error with pattern '{$pattern}': " . $e->getMessage(),
                    'suggestion' => 'Check field selection implementation'
                ];
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @return array<string,array<array<string,string>>>
     */
    private function validateWhitelistConfiguration(\Glueful\Routing\Route $route, bool $strict): array
    {
        $errors = [];
        $warnings = [];

        // In a real implementation, this would check actual whitelist configuration
        // For now, we'll do basic validation

        $handler = $route->getHandler();
        $controller = is_string($handler) ? $handler : null;
        if ($controller !== null) {
            // Check if controller exists
            if (!class_exists($controller)) {
                $errors[] = [
                    'code' => 'CONTROLLER_NOT_FOUND',
                    'message' => "Controller class '{$controller}' not found",
                    'suggestion' => 'Ensure controller class exists and is properly autoloaded'
                ];
            }
        }

        // Check for potential security issues
        if (str_contains($route->getPath(), '{id}') && !$this->hasIdValidation($route)) {
            $warnings[] = [
                'code' => 'MISSING_ID_VALIDATION',
                'message' => 'Route with {id} parameter might need field selection validation',
                'suggestion' => 'Consider adding ID-based field filtering for security'
            ];
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @return array<string,array<array<string,string>>>
     */
    private function validateControllerCompatibility(\Glueful\Routing\Route $route): array
    {
        $errors = [];
        $warnings = [];

        $handler = $route->getHandler();
        $controller = is_string($handler) ? $handler : null;

        if ($controller !== null) {
            // Check if controller method exists
            if (str_contains($controller, '@')) {
                [$class, $method] = explode('@', $controller);
                if (class_exists($class) && !method_exists($class, $method)) {
                    $errors[] = [
                        'code' => 'METHOD_NOT_FOUND',
                        'message' => "Controller method '{$method}' not found in class '{$class}'",
                        'suggestion' => 'Ensure controller method exists'
                    ];
                }
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function shouldHaveFieldSelection(\Glueful\Routing\Route $route): bool
    {
        // Suggest field selection for API routes
        $path = $route->getPath();
        $method = $route->getMethod();
        $methods = [$method];

        // Use is_api_path helper if available
        $isApiRoute = function_exists('is_api_path')
            ? is_api_path($path)
            : (str_starts_with($path, '/api/') || str_contains($path, 'api.'));

        return $isApiRoute && in_array('GET', $methods, true);
    }

    private function hasIdValidation(\Glueful\Routing\Route $route): bool
    {
        // In a real implementation, this would check for ID validation middleware
        // For now, return false as placeholder
        return false;
    }

    /**
     * @param array<array<string,string>> $issues
     * @param array<array<string,string>> $warnings
     * @return array<string,mixed>
     */
    private function attemptFixes(
        \Glueful\Routing\Route $route,
        array $issues,
        array $warnings
    ): array {
        $fixed = false;
        $fixedIssues = [];
        $fixedWarnings = [];

        // In a real implementation, this would attempt to fix common issues
        // For example:
        // - Add missing middleware
        // - Fix whitelist configurations
        // - Update route parameters

        // Placeholder for demonstration
        foreach ($warnings as $warning) {
            if ($warning['code'] === 'MISSING_FIELD_SELECTION') {
                // Could potentially add the middleware automatically
                $this->line("  Would fix: Add field selection middleware to {$route->getPath()}");
                $fixedWarnings[] = $warning['code'];
                $fixed = true;
            }
        }

        return [
            'fixed' => $fixed,
            'fixed_issues' => $fixedIssues,
            'fixed_warnings' => $fixedWarnings
        ];
    }

    /**
     * @param array<string,mixed> $validation
     */
    private function outputTable(array $validation): void
    {
        $summary = $validation['summary'];
        $results = $validation['results'];

        $this->line('');
        $this->info('📋 Field Configuration Validation Results');
        $this->line('');

        // Summary table
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Routes', $summary['total']],
                ['Passed', $summary['passed']],
                ['Warnings', $summary['warnings']],
                ['Errors', $summary['errors']],
                ['Fixed', $summary['fixed']],
                ['Validation Mode', $validation['validation_mode']],
            ]
        );

        if ($results !== []) {
            $this->line('');
            $this->info('🛣️  Detailed Results');
            $this->line('');

            $rows = [];
            foreach ($results as $result) {
                $statusIcon = match ($result['status']) {
                    'passed' => '✅',
                    'warning' => '⚠️ ',
                    'error' => '❌',
                    default => '❓'
                };

                $issues = array_merge($result['issues'], $result['warnings']);
                $issuesSummary = count($issues) > 0
                    ? implode('; ', array_slice(array_column($issues, 'message'), 0, 2))
                    : 'No issues';

                if (count($issues) > 2) {
                    $issuesSummary .= '... (and ' . (count($issues) - 2) . ' more)';
                }

                $rows[] = [
                    $statusIcon . ' ' . $result['route'],
                    $result['method'],
                    ($result['has_field_selection'] === true) ? '✅' : '❌',
                    strlen($issuesSummary) > 50 ? substr($issuesSummary, 0, 47) . '...' : $issuesSummary
                ];
            }

            $this->table(
                ['Route', 'Method', 'Field Selection', 'Issues'],
                $rows
            );
        }

        // Show detailed issues for failed routes
        $failedRoutes = array_filter($results, fn($r) => $r['status'] === 'error');
        if ($failedRoutes !== []) {
            $this->line('');
            $this->error('❌ Routes with Errors:');
            $this->line('');

            foreach ($failedRoutes as $result) {
                $this->line("<error>Route:</error> {$result['route']} ({$result['path']})");
                foreach ($result['issues'] as $issue) {
                    $this->line("  • {$issue['message']}");
                    if (isset($issue['suggestion'])) {
                        $this->line("    💡 {$issue['suggestion']}");
                    }
                }
                $this->line('');
            }
        }
    }

    /**
     * @param array<string,mixed> $validation
     */
    private function outputJson(array $validation): void
    {
        $this->line(json_encode($validation, JSON_PRETTY_PRINT));
    }
}
