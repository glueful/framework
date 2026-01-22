<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks\Contracts;

/**
 * Contract for building webhook payloads
 */
interface WebhookPayloadInterface
{
    /**
     * Build webhook payload
     *
     * @param string $event Event name
     * @param array<string, mixed> $data Event data
     * @param array<string, mixed> $metadata Optional metadata
     * @return array<string, mixed> Formatted payload
     */
    public function build(string $event, array $data, array $metadata = []): array;
}
