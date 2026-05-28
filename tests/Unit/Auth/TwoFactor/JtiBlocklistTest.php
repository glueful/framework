<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth\TwoFactor;

use Glueful\Auth\TwoFactor\JtiBlocklist;
use Glueful\Cache\Drivers\ArrayCacheDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JtiBlocklist::class)]
final class JtiBlocklistTest extends TestCase
{
    public function testConsumeMarksJtiConsumed(): void
    {
        $blocklist = new JtiBlocklist(new ArrayCacheDriver());

        $this->assertFalse($blocklist->isConsumed('jti-1'));

        $blocklist->consume('jti-1', 300);

        $this->assertTrue($blocklist->isConsumed('jti-1'));
    }

    public function testUnconsumedJtiIsNotConsumed(): void
    {
        $blocklist = new JtiBlocklist(new ArrayCacheDriver());

        $this->assertFalse($blocklist->isConsumed('never-seen'));
    }

    public function testConsumeIsScopedPerJti(): void
    {
        $blocklist = new JtiBlocklist(new ArrayCacheDriver());

        $blocklist->consume('jti-a', 300);

        $this->assertTrue($blocklist->isConsumed('jti-a'));
        $this->assertFalse($blocklist->isConsumed('jti-b'));
    }

    public function testDoubleConsumeIsIdempotent(): void
    {
        $blocklist = new JtiBlocklist(new ArrayCacheDriver());

        $blocklist->consume('jti-2', 300);
        $blocklist->consume('jti-2', 300);

        $this->assertTrue($blocklist->isConsumed('jti-2'));
    }
}
