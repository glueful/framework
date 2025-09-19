<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\DefinitionInterface;

final class SerializerProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // Minimal Glueful serializer
        $defs[\Glueful\Serialization\Serializer::class] =
            $this->autowire(\Glueful\Serialization\Serializer::class);

        return $defs;
    }
}
