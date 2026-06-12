<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth;

use Glueful\Auth\JWTService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class JWTServiceValidationTest extends TestCase
{
    private const KEY = 'jwt-validation-test-key';

    protected function setUp(): void
    {
        parent::setUp();

        $ref = new ReflectionClass(JWTService::class);
        $ref->getProperty('key')->setValue(null, self::KEY);
    }

    public function testDecodeRejectsTokenWithoutExpiration(): void
    {
        $token = $this->sign(['sub' => 'user-1', 'iat' => time()]);

        $this->assertNull(JWTService::decode($token));
    }

    public function testDecodeRejectsTokenBeforeNotBefore(): void
    {
        $token = $this->sign([
            'sub' => 'user-1',
            'iat' => time(),
            'nbf' => time() + 60,
            'exp' => time() + 3600,
        ]);

        $this->assertNull(JWTService::decode($token));
    }

    public function testDecodeRejectsTokenIssuedInTheFuture(): void
    {
        $token = $this->sign([
            'sub' => 'user-1',
            'iat' => time() + 60,
            'exp' => time() + 3600,
        ]);

        $this->assertNull(JWTService::decode($token));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sign(array $payload): string
    {
        $headerEncoded = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
        $data = $headerEncoded . '.' . $payloadEncoded;
        $signature = hash_hmac('sha256', $data, self::KEY, true);

        return $data . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string|false $data): string
    {
        $data = $data === false ? '' : $data;
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
