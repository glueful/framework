<?php

declare(strict_types=1);

namespace Glueful\Support\Documentation;

/**
 * Documentation UI Generator
 *
 * Generates interactive HTML documentation pages using various UI libraries:
 * - Scalar (default) - Modern, beautiful API documentation
 * - Swagger UI - Classic OpenAPI documentation interface
 * - Redoc - Clean, responsive three-panel design
 */
class DocumentationUIGenerator
{
    private const SUPPORTED_UIS = ['scalar', 'swagger-ui', 'redoc'];

    /**
     * Generate documentation UI HTML file
     *
     * @param string $ui UI type (scalar, swagger-ui, redoc)
     * @param string|null $outputPath Custom output path for HTML file
     * @return string Path to generated file
     * @throws \InvalidArgumentException If UI type is not supported
     */
    public function generate(string $ui = 'scalar', ?string $outputPath = null): string
    {
        $ui = strtolower($ui);

        if (!in_array($ui, self::SUPPORTED_UIS, true)) {
            throw new \InvalidArgumentException(
                "Unsupported UI type: {$ui}. Supported: " . implode(', ', self::SUPPORTED_UIS)
            );
        }

        $outputPath = $outputPath ?? $this->getDefaultOutputPath();
        $html = $this->generateHtml($ui);

        $this->ensureDirectory(dirname($outputPath));

        if (file_put_contents($outputPath, $html) === false) {
            throw new \RuntimeException("Failed to write documentation UI to: {$outputPath}");
        }

        return $outputPath;
    }

    /**
     * Get default output path for the HTML file
     */
    private function getDefaultOutputPath(): string
    {
        $outputDir = config('documentation.paths.output', base_path('docs'));
        $filename = config('documentation.ui.filename', 'index.html');

        return rtrim($outputDir, '/') . '/' . $filename;
    }

    /**
     * Generate HTML content for the specified UI
     */
    private function generateHtml(string $ui): string
    {
        return match ($ui) {
            'scalar' => $this->generateScalarHtml(),
            'swagger-ui' => $this->generateSwaggerUIHtml(),
            'redoc' => $this->generateRedocHtml(),
            default => throw new \InvalidArgumentException("Unknown UI: {$ui}"),
        };
    }

    /**
     * Generate Scalar documentation HTML
     */
    private function generateScalarHtml(): string
    {
        $title = $this->escapeHtml(config('documentation.ui.title', 'API Documentation'));
        $config = config('documentation.ui.scalar', []);

        $theme = is_string($config['theme'] ?? null) ? $config['theme'] : 'purple';
        $darkMode = (bool) ($config['dark_mode'] ?? true) ? 'true' : 'false';
        $hideDownload = (bool) ($config['hide_download_button'] ?? false) ? 'true' : 'false';
        $hideClient = (bool) ($config['hide_client_button'] ?? true) ? 'true' : 'false';
        $hideModels = (bool) ($config['hide_models'] ?? false) ? 'true' : 'false';
        $defaultOpenAllTags = (bool) ($config['default_open_all_tags'] ?? false) ? 'true' : 'false';
        $showDevTools = is_string($config['show_developer_tools'] ?? null) ? $config['show_developer_tools'] : 'never';
        $hidePoweredBadge = (bool) ($config['hide_powered_badge'] ?? true) ? 'true' : 'false';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <style>
        body { margin: 0; padding: 0; }
    </style>
</head>
<body>
    <script id="api-reference" data-url="/docs/openapi.json"></script>
    <script>
        var configuration = {
            theme: '{$theme}',
            darkMode: {$darkMode},
            hideDownloadButton: {$hideDownload},
            hideClientButton: {$hideClient},
            hideModels: {$hideModels},
            defaultOpenAllTags: {$defaultOpenAllTags},
            showDeveloperTools: '{$showDevTools}',
            metaData: {
                hidePoweredBadge: {$hidePoweredBadge}
            }
        };
        document.getElementById('api-reference').dataset.configuration = JSON.stringify(configuration);
    </script>
    <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference"></script>
</body>
</html>
HTML;
    }

    /**
     * Generate Swagger UI documentation HTML
     */
    private function generateSwaggerUIHtml(): string
    {
        $title = $this->escapeHtml(config('documentation.ui.title', 'API Documentation'));
        $config = config('documentation.ui.swagger_ui', []);

        $deepLinking = (bool) ($config['deep_linking'] ?? true) ? 'true' : 'false';
        $displayRequestDuration = (bool) ($config['display_request_duration'] ?? true) ? 'true' : 'false';
        $filter = (bool) ($config['filter'] ?? true) ? 'true' : 'false';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
    <style>
        body { margin: 0; padding: 0; }
        .swagger-ui .topbar { display: none; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-standalone-preset.js"></script>
    <script>
        window.onload = function() {
            SwaggerUIBundle({
                url: '/docs/openapi.json',
                dom_id: '#swagger-ui',
                deepLinking: {$deepLinking},
                displayRequestDuration: {$displayRequestDuration},
                filter: {$filter},
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIStandalonePreset
                ],
                plugins: [
                    SwaggerUIBundle.plugins.DownloadUrl
                ],
                layout: 'StandaloneLayout'
            });
        };
    </script>
</body>
</html>
HTML;
    }

    /**
     * Generate Redoc documentation HTML
     */
    private function generateRedocHtml(): string
    {
        $title = $this->escapeHtml(config('documentation.ui.title', 'API Documentation'));
        $config = config('documentation.ui.redoc', []);

        $expandResponses = is_string($config['expand_responses'] ?? null) ? $config['expand_responses'] : '200,201';
        $hideDownload = (bool) ($config['hide_download_button'] ?? false) ? 'true' : 'false';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
    </style>
</head>
<body>
    <redoc
        spec-url="/docs/openapi.json"
        expand-responses="{$expandResponses}"
        hide-download-button="{$hideDownload}"
        lazy-rendering
    ></redoc>
    <script src="https://cdn.jsdelivr.net/npm/redoc@latest/bundles/redoc.standalone.js"></script>
</body>
</html>
HTML;
    }

    /**
     * Escape HTML special characters
     */
    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Ensure directory exists
     */
    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    /**
     * Get list of supported UI types
     *
     * @return array<string>
     */
    public static function getSupportedUIs(): array
    {
        return self::SUPPORTED_UIS;
    }
}
