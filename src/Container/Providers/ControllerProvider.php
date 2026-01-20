<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\{DefinitionInterface};

final class ControllerProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // Register common controllers; most have no deps or are autowirable
        $defs[\Glueful\Controllers\AuthController::class] =
            $this->autowire(\Glueful\Controllers\AuthController::class);
        $defs[\Glueful\Controllers\ConfigController::class] =
            $this->autowire(\Glueful\Controllers\ConfigController::class);
        $defs[\Glueful\Controllers\ResourceController::class] =
            $this->autowire(\Glueful\Controllers\ResourceController::class);
        $defs[\Glueful\Controllers\MetricsController::class] =
            $this->autowire(\Glueful\Controllers\MetricsController::class);
        $defs[\Glueful\Controllers\HealthController::class] =
            $this->autowire(\Glueful\Controllers\HealthController::class);
        $defs[\Glueful\Controllers\ExtensionsController::class] =
            $this->autowire(\Glueful\Controllers\ExtensionsController::class);
        $defs[\Glueful\Controllers\DocsController::class] =
            $this->autowire(\Glueful\Controllers\DocsController::class);

        return $defs;
    }
}
