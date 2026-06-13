<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support;

use Glueful\Support\SensitiveParamRedactor;
use PHPUnit\Framework\TestCase;

final class SensitiveParamRedactorTest extends TestCase
{
    public function testRedactsSubstringAndExactSensitiveNames(): void
    {
        $url = '/callback?access_token=tok-v&x_signature=sig-v&authorization_code=code-v'
            . '&new_password=pw-v&code=oauth-v&state=keepme';

        $sanitized = SensitiveParamRedactor::sanitizeUrl($url);

        $this->assertIsString($sanitized);
        $this->assertStringNotContainsString('tok-v', $sanitized);
        $this->assertStringNotContainsString('sig-v', $sanitized);
        $this->assertStringNotContainsString('code-v', $sanitized);
        $this->assertStringNotContainsString('pw-v', $sanitized);
        $this->assertStringNotContainsString('oauth-v', $sanitized);
        $this->assertStringContainsString('state=keepme', $sanitized);
    }

    public function testSensitiveKeyRedactsWholeArraySubtree(): void
    {
        $data = [
            'credentials' => ['api_key' => 'k', 'note' => 'n'],
            'token' => ['nested' => 'leak'],
            'plain' => 'visible',
        ];

        SensitiveParamRedactor::sanitizeArray($data);

        $this->assertSame(SensitiveParamRedactor::REDACTED, $data['token']);
        $this->assertSame(SensitiveParamRedactor::REDACTED, $data['credentials']['api_key']);
        $this->assertSame('n', $data['credentials']['note']);
        $this->assertSame('visible', $data['plain']);
    }

    public function testDropsUserinfoAndFragment(): void
    {
        $sanitized = SensitiveParamRedactor::sanitizeUrl('https://user:pass@host.test/path?x=1#frag');

        $this->assertSame('https://host.test/path?x=1', $sanitized);
    }

    public function testUnparseableUrlIsFullyRedacted(): void
    {
        $this->assertSame(
            SensitiveParamRedactor::REDACTED,
            SensitiveParamRedactor::sanitizeUrl('http://')
        );
    }

    public function testNullAndEmptyPassThrough(): void
    {
        $this->assertNull(SensitiveParamRedactor::sanitizeUrl(null));
        $this->assertSame('', SensitiveParamRedactor::sanitizeUrl(''));
        $this->assertNull(SensitiveParamRedactor::sanitizeQueryString(null));
    }
}
