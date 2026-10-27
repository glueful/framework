<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing\Middleware;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Middleware\FieldSelectionMiddleware;
use Glueful\Support\FieldSelection\Projector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * `api.field_selection.enabled` was never read, so `?fields=` projected responses even with the
 * feature switched off.
 */
final class FieldSelectionEnabledSwitchTest extends TestCase
{
    /** @return array<string, mixed> */
    private function project(bool $enabled): array
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/field_selection_switch_' . uniqid());
        $context->mergeConfigDefaults('api', ['field_selection' => ['enabled' => $enabled]]);

        $request = Request::create('/things', 'GET', ['fields' => 'id']);
        $response = (new FieldSelectionMiddleware(new Projector(), $context))->handle(
            $request,
            static fn() => new JsonResponse(['id' => 1, 'name' => 'Ada'])
        );

        /** @var array<string, mixed> */
        return json_decode((string) $response->getContent(), true);
    }

    public function testDisabledLeavesTheResponseUnprojected(): void
    {
        self::assertSame(['id' => 1, 'name' => 'Ada'], $this->project(false));
    }

    public function testEnabledProjects(): void
    {
        self::assertSame(['id' => 1], $this->project(true));
    }
}
