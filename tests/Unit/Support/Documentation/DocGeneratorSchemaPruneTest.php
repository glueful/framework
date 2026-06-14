<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Support\Documentation\DocGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Covers Phase-0 #2: opt-in pruning of unreferenced default component schemas
 * (config `documentation.options.prune_unreferenced_schemas`, default false).
 */
final class DocGeneratorSchemaPruneTest extends TestCase
{
    /** @return array<string, mixed> */
    private function schemas(): array
    {
        return [
            // referenced by a path; itself references Transitive
            'Referenced' => [
                'type' => 'object',
                'properties' => ['dep' => ['$ref' => '#/components/schemas/Transitive']],
            ],
            'Transitive' => ['type' => 'object'],
            // referenced by nothing -> should be pruned
            'Unused' => ['type' => 'object'],
            // referenced only by a webhook
            'HookSchema' => ['type' => 'object'],
            // came from a route/extension fragment -> always kept
            'Fragment' => ['type' => 'object'],
        ];
    }

    /** @return array<string, mixed> */
    private function pathsRef(string $name): array
    {
        return [
            '/x' => [
                'get' => [
                    'responses' => [
                        '200' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/' . $name],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function testPruneKeepsReferencedAndTransitiveDropsUnused(): void
    {
        $kept = DocGenerator::pruneUnreferencedSchemas(
            $this->schemas(),
            [],                         // nothing always-kept
            $this->pathsRef('Referenced'),
            []
        );

        self::assertArrayHasKey('Referenced', $kept);
        self::assertArrayHasKey('Transitive', $kept, 'transitive ref must survive');
        self::assertArrayNotHasKey('Unused', $kept, 'unreferenced schema must be pruned');
        self::assertArrayNotHasKey('HookSchema', $kept);
        self::assertArrayNotHasKey('Fragment', $kept);
    }

    public function testPruneAlwaysKeepsFragmentSchemas(): void
    {
        $alwaysKeep = ['Fragment' => ['type' => 'object']];

        $kept = DocGenerator::pruneUnreferencedSchemas(
            $this->schemas(),
            $alwaysKeep,
            $this->pathsRef('Referenced'),
            []
        );

        // Fragment is unreferenced but explicitly documented -> retained.
        self::assertArrayHasKey('Fragment', $kept);
        self::assertArrayNotHasKey('Unused', $kept);
    }

    public function testPruneKeepsWebhookReferencedSchemas(): void
    {
        $webhooks = [
            'onEvent' => [
                'post' => [
                    'requestBody' => [
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/HookSchema'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $kept = DocGenerator::pruneUnreferencedSchemas(
            $this->schemas(),
            [],
            [],         // no paths
            $webhooks
        );

        self::assertArrayHasKey('HookSchema', $kept, 'schema referenced only by a webhook must survive');
        self::assertArrayNotHasKey('Unused', $kept);
        self::assertArrayNotHasKey('Referenced', $kept);
    }

    public function testKeyOrderIsPreserved(): void
    {
        $kept = DocGenerator::pruneUnreferencedSchemas(
            $this->schemas(),
            ['Fragment' => ['type' => 'object']],
            $this->pathsRef('Referenced'),
            []
        );

        // Original relative order (Referenced, Transitive, Fragment) is kept.
        self::assertSame(['Referenced', 'Transitive', 'Fragment'], array_keys($kept));
    }

    public function testDefaultSchemasAreKeptWhenPruneDisabled(): void
    {
        // Null context => getConfig returns the default (false) => prune is OFF.
        $generator = new DocGenerator(openApiVersion: '3.1.0');
        $spec = json_decode($generator->getSwaggerJson(), true);

        self::assertIsArray($spec);
        // Built-in defaults remain present (backward compatible) even with no paths.
        self::assertArrayHasKey('ErrorResponse', $spec['components']['schemas']);
        self::assertArrayHasKey('SuccessResponse', $spec['components']['schemas']);
    }

    public function testConfigDefaultsPruneOff(): void
    {
        $config = require dirname(__DIR__, 4) . '/config/documentation.php';

        self::assertArrayHasKey('prune_unreferenced_schemas', $config['options']);
        self::assertFalse($config['options']['prune_unreferenced_schemas']);
    }
}
