<?php

namespace Glueful\Console\Commands\Security;

use Glueful\Console\Commands\Security\BaseSecurityCommand;
use Glueful\Security\SecurityManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Security configuration report exporter.
 *
 * Exports the same configuration audit performed by `security:check` in HTML,
 * JSON, or plain-text form, suitable for archival or sharing. Sources are
 * limited to data the framework can introspect directly:
 *
 *   - Production readiness score and warnings (SecurityManager)
 *   - Environment configuration (APP_DEBUG, APP_KEY, JWT_KEY presence)
 *   - System info (PHP version, loaded extensions, ini settings)
 *
 * Telemetry-style sections (logins, audit events, vulnerability counts,
 * request volume) and the hardcoded `compliance` block (GDPR/headers/etc.)
 * were removed in 1.43.x because they returned fixed strings rather than
 * data derived from real introspection. Use `security:vulnerabilities` for
 * dependency CVE scanning; runtime/auth metrics belong in a future report
 * once they are wired to real sources.
 *
 * @package Glueful\Console\Commands\Security
 */
#[AsCommand(
    name: 'security:report',
    description: 'Export security configuration audit (HTML/JSON/text)'
)]
class ReportCommand extends BaseSecurityCommand
{
    protected function configure(): void
    {
        $this->setDescription('Export security configuration audit (HTML/JSON/text)')
             ->setHelp(
                 "Exports the security configuration audit in HTML, JSON, or plain-text form.\n\n" .
                 "Sources are limited to data the framework can introspect directly:\n" .
                 "  - Production readiness score and warnings\n" .
                 "  - Environment configuration (debug mode, key presence)\n" .
                 "  - System info (PHP version, extensions, ini limits)\n\n" .
                 "For dependency vulnerability scanning, use: php glueful security:vulnerabilities"
             )
             ->addOption(
                 'format',
                 'f',
                 InputOption::VALUE_REQUIRED,
                 'Report format (html, json, text)',
                 'html'
             )
             ->addOption(
                 'output',
                 'o',
                 InputOption::VALUE_REQUIRED,
                 'Output file path for the report'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');
        $outputFile = $input->getOption('output') !== null ? (string) $input->getOption('output') : null;

        $validFormats = ['html', 'json', 'text'];
        if (!in_array($format, $validFormats, true)) {
            $this->error("Invalid format: {$format}");
            $this->info('Valid formats: ' . implode(', ', $validFormats));
            return self::FAILURE;
        }

        $this->info("Generating security configuration report (format: {$format})...");

        try {
            $reportData = $this->gatherSecurityReportData();
            $report = $this->generateSecurityReport($reportData, $format);

            if ($outputFile !== null) {
                $this->saveReportToFile($report, $outputFile);
            } else {
                $output->writeln($report);
            }

            $this->displayReportSummary($reportData['summary'], $format, $outputFile);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Report generation failed: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function gatherSecurityReportData(): array
    {
        $data = [
            'metadata' => [
                'generated_at' => date('Y-m-d H:i:s'),
                'server' => gethostname(),
                'environment' => env('APP_ENV', 'unknown'),
            ],
            'security_config' => $this->analyzeSecurityConfiguration(),
            'system_health' => $this->analyzeSystemSecurity(),
            'recommendations' => [],
        ];

        $data['recommendations'] = $this->generateSecurityRecommendations($data);
        $data['summary'] = $this->createReportSummary($data);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeSecurityConfiguration(): array
    {
        $prodValidation = SecurityManager::validateProductionEnvironment();
        $scoreData = SecurityManager::getProductionReadinessScore();

        return [
            'production_readiness' => [
                'score' => $scoreData['score'],
                'status' => $scoreData['status'],
                'warnings' => $prodValidation['warnings'],
                'recommendations' => $prodValidation['recommendations'],
            ],
            'environment_security' => [
                'debug_mode' => env('APP_DEBUG', false),
                'environment' => env('APP_ENV', 'unknown'),
                'app_key_set' => (env('APP_KEY') !== null && env('APP_KEY') !== ''),
                'jwt_key_set' => (env('JWT_KEY') !== null && env('JWT_KEY') !== ''),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeSystemSecurity(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'extensions_loaded' => get_loaded_extensions(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private function generateSecurityRecommendations(array $data): array
    {
        $recommendations = [];

        if ($data['security_config']['production_readiness']['score'] < 80) {
            $recommendations[] = 'Improve production readiness score by addressing security warnings';
        }

        if (($data['security_config']['environment_security']['debug_mode'] ?? false) === true) {
            $recommendations[] = 'Disable debug mode in production';
        }

        if ($data['security_config']['environment_security']['app_key_set'] !== true) {
            $recommendations[] = 'Set APP_KEY in environment for encryption support';
        }

        if ($data['security_config']['environment_security']['jwt_key_set'] !== true) {
            $recommendations[] = 'Set JWT_KEY in environment for JWT authentication';
        }

        return $recommendations;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function createReportSummary(array $data): array
    {
        $score = $data['security_config']['production_readiness']['score'] ?? 0;
        $recommendationCount = count($data['recommendations']);

        return [
            'overall_score' => $score,
            'security_status' => $score >= 80 ? 'Good' : ($score >= 60 ? 'Fair' : 'Poor'),
            'recommendations_count' => $recommendationCount,
            'report_date' => $data['metadata']['generated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function generateSecurityReport(array $data, string $format): string
    {
        switch ($format) {
            case 'json':
                $result = json_encode($data, JSON_PRETTY_PRINT);
                return $result !== false ? $result : '';
            case 'html':
                return $this->generateHtmlReport($data);
            default:
                return $this->generateTextReport($data);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function generateHtmlReport(array $data): string
    {
        $html = "<html><head><title>Security Configuration Report</title></head><body>";
        $html .= "<h1>Security Configuration Report</h1>";
        $html .= "<p>Generated: " . htmlspecialchars((string) $data['metadata']['generated_at']) . "</p>";
        $html .= "<p>Environment: " . htmlspecialchars((string) $data['metadata']['environment']) . "</p>";
        $html .= "<h2>Security Score: "
            . htmlspecialchars((string) $data['summary']['overall_score']) . "/100</h2>";

        $html .= "<h3>Recommendations:</h3><ul>";
        foreach ($data['recommendations'] as $rec) {
            $html .= "<li>" . htmlspecialchars((string) $rec) . "</li>";
        }
        $html .= "</ul>";

        $html .= "</body></html>";
        return $html;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function generateTextReport(array $data): string
    {
        $report = "SECURITY CONFIGURATION REPORT\n";
        $report .= "=============================\n\n";
        $report .= "Generated: {$data['metadata']['generated_at']}\n";
        $report .= "Environment: {$data['metadata']['environment']}\n";
        $score = $data['summary']['overall_score'];
        $status = $data['summary']['security_status'];
        $report .= "Security Score: {$score}/100 ({$status})\n\n";

        $report .= "RECOMMENDATIONS:\n";
        if (count($data['recommendations']) === 0) {
            $report .= "  (none)\n";
        }
        foreach ($data['recommendations'] as $i => $rec) {
            $report .= ($i + 1) . ". {$rec}\n";
        }

        return $report;
    }

    private function saveReportToFile(string $report, string $output): void
    {
        $dir = dirname($output);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($output, $report);
        $this->success("Report saved to: {$output}");
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function displayReportSummary(array $summary, string $format, ?string $output): void
    {
        $this->line('');
        $this->info('Report Summary:');
        $this->line('================');

        $summaryData = [
            ['Report Format', ucfirst($format)],
            ['Generated At', $summary['report_date']],
            ['Security Score', $summary['overall_score'] . '/100'],
            ['Security Status', $summary['security_status']],
            ['Recommendations', $summary['recommendations_count']],
        ];

        if ($output !== null) {
            $summaryData[] = ['Saved To', $output];
        }

        $this->table(['Property', 'Value'], $summaryData);

        if ($summary['overall_score'] < 70) {
            $this->line('');
            $this->warning('Security score is below recommended threshold (70)');
            $this->info('Review the recommendations to improve your security posture');
        }
    }
}
