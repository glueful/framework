<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Support\Documentation\OperationIdGenerator;
use PHPUnit\Framework\TestCase;

final class OperationIdGeneratorTest extends TestCase
{
    public function testUsesRouteNameWhenAvailable(): void
    {
        $gen = new OperationIdGenerator();
        self::assertSame('usersShow', $gen->fromRouteName('users.show'));
        self::assertSame('apiV1UsersIndex', $gen->fromRouteName('api.v1.users.index'));
    }

    public function testDerivesFromMethodAndPathWhenNoName(): void
    {
        $gen = new OperationIdGenerator();
        self::assertSame('getV1Users', $gen->fromMethodAndPath('GET', '/v1/users'));
        self::assertSame('getV1UsersByUuid', $gen->fromMethodAndPath('GET', '/v1/users/{uuid}'));
        self::assertSame('postV1UsersByUuidActivate', $gen->fromMethodAndPath('POST', '/v1/users/{uuid}/activate'));
    }

    public function testProducesUniqueIdsWhenCollisionsHappen(): void
    {
        $gen = new OperationIdGenerator();
        self::assertSame('listUsers', $gen->register('listUsers'));
        self::assertSame('listUsers2', $gen->register('listUsers'));
        self::assertSame('listUsers3', $gen->register('listUsers'));
    }
}
