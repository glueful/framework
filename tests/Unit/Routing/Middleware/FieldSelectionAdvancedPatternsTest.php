<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing\Middleware;

use Glueful\Routing\Middleware\FieldSelectionMiddleware;
use Glueful\Routing\Route;
use Glueful\Support\FieldSelection\Projector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * #[Fields(useAdvancedPatterns: true)] must make the route's `allowed` list behave as advanced
 * whitelist patterns (wildcards, `-exclusions`, `field:if(condition)`), the documented
 * AdvancedWhitelistMatcher syntax. Previously the flag was accepted but never read, so the
 * patterns were compared as literal field names.
 */
final class FieldSelectionAdvancedPatternsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $payload = [
        'id' => 1,
        'name' => 'Ada',
        'password' => 'hash',
        'email' => 'ada@example.com',
        'user_id' => 7,
    ];

    public function testExclusionPatternDropsExcludedField(): void
    {
        $out = $this->project(['allowed' => ['*,-password'], 'useAdvancedPatterns' => true], 'id,name,password');

        $this->assertSame(['id' => 1, 'name' => 'Ada'], $out);
    }

    public function testConditionalPatternDropsFieldWhenConditionFails(): void
    {
        $out = $this->project(
            ['allowed' => ['id', 'email:if(owner)'], 'useAdvancedPatterns' => true],
            'id,email',
            ['id' => 99]
        );

        $this->assertSame(['id' => 1], $out);
    }

    public function testConditionalPatternKeepsFieldWhenConditionPasses(): void
    {
        $out = $this->project(
            ['allowed' => ['id', 'email:if(owner)'], 'useAdvancedPatterns' => true],
            'id,email',
            ['id' => 7]
        );

        $this->assertSame(['id' => 1, 'email' => 'ada@example.com'], $out);
    }

    public function testStrictAdvancedRejectsDisallowedField(): void
    {
        $response = $this->dispatch(
            ['allowed' => ['*', '-password'], 'useAdvancedPatterns' => true, 'strict' => true],
            'id,password'
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testStrictAdvancedAcceptsPatternMatchedFields(): void
    {
        // Literal strict matching would reject 'id' and 'name' (not in ['*', '-password']).
        $out = $this->project(
            ['allowed' => ['*', '-password'], 'useAdvancedPatterns' => true, 'strict' => true],
            'id,name'
        );

        $this->assertSame(['id' => 1, 'name' => 'Ada'], $out);
    }

    public function testWithoutFlagPatternsStayLiteral(): void
    {
        // Legacy behaviour unchanged: non-strict literal whitelist does not filter requested roots.
        $out = $this->project(['allowed' => ['*,-password']], 'id,password');

        $this->assertSame(['id' => 1, 'password' => 'hash'], $out);
    }

    /**
     * @param array<string, mixed> $fieldsConfig
     * @param array<string, mixed>|null $user
     * @return array<string, mixed>
     */
    private function project(array $fieldsConfig, string $fields, ?array $user = null): array
    {
        $response = $this->dispatch($fieldsConfig, $fields, $user);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true);
        return $decoded;
    }

    /**
     * @param array<string, mixed> $fieldsConfig
     * @param array<string, mixed>|null $user
     */
    private function dispatch(array $fieldsConfig, string $fields, ?array $user = null): Response
    {
        /** @var Route $route */
        $route = (new \ReflectionClass(Route::class))->newInstanceWithoutConstructor();
        $route->setFieldsConfig($fieldsConfig);

        $request = Request::create('/things', 'GET', ['fields' => $fields]);
        $request->attributes->set('_route', $route);
        if ($user !== null) {
            $request->attributes->set('user', $user);
        }

        $middleware = new FieldSelectionMiddleware(new Projector());
        $response = $middleware->handle($request, fn(Request $r) => new JsonResponse($this->payload));
        $this->assertInstanceOf(Response::class, $response);

        return $response;
    }
}
