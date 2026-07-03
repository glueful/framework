<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Security;

use Glueful\Security\RandomStringGenerator;
use PHPUnit\Framework\TestCase;

class RandomStringGeneratorTest extends TestCase
{
    public function testGeneratesRequestedLengthFromCharset(): void
    {
        $out = RandomStringGenerator::generate(24, 'abc123');
        self::assertSame(24, strlen($out));
        self::assertMatchesRegularExpression('/\A[abc123]{24}\z/', $out);
    }

    /**
     * Regression: the rejection-sampling inner loop read past the random-byte
     * buffer when rejections clustered at its end ("Uninitialized string
     * offset 32" — flaky, surfaced as csv_user_import_failed in CI). A 65-char
     * charset maximizes the rejection rate (mask 127 -> ~49% per draw); with
     * the bug, thousands of short generates trip the overrun essentially
     * every run. Warnings are escalated so the overrun cannot pass silently
     * (ord('') would otherwise just bias output toward charset[0]).
     */
    public function testRejectionSamplingNeverReadsPastTheByteBuffer(): void
    {
        $charset = implode('', array_map('chr', range(48, 112))); // 65 chars
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            for ($i = 0; $i < 20000; $i++) {
                $out = RandomStringGenerator::generate(16, $charset);
                self::assertSame(16, strlen($out));
            }
        } finally {
            restore_error_handler();
        }
    }
}
