<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Extensions\ExtensionManager;

/**
 * Simplified read-only Extensions API
 *
 * Provides basic extension information for monitoring and CLI support.
 * Extension management is now done via CLI commands and config files.
 */
class ExtensionsController extends BaseController
{
    public function __construct(
        private ExtensionManager $extensionManager
    ) {
        parent::__construct();
    }

    /**
     * List all discovered extensions (read-only)
     *
     * @return mixed HTTP response
     */
    public function index(): mixed
    {
        $providers = $this->extensionManager->getProviders();
        $meta = $this->extensionManager->listMeta();

        $extensions = [];
        foreach ($providers as $class => $provider) {
            $m = $meta[$class] ?? [];
            $extensions[] = [
                'slug' => $m['slug'] ?? basename(str_replace('\\', '/', $class)),
                'name' => $m['name'] ?? $class,
                'version' => $m['version'] ?? 'n/a',
                'description' => $m['description'] ?? '',
                'provider' => $class,
            ];
        }

        return Response::success([
            'extensions' => $extensions,
            'total' => count($extensions)
        ], 'Extensions retrieved successfully');
    }

    /**
     * Get extension system summary
     *
     * @return mixed HTTP response
     */
    public function summary(): mixed
    {
        return Response::success(
            $this->extensionManager->getSummary(),
            'Extension system summary retrieved successfully'
        );
    }
}
