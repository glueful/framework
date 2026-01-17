<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Async;

use Glueful\Async\FiberScheduler;
use Glueful\Async\Http\CurlMultiHttpClient;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class HttpPoolingConfigTest extends TestCase
{
    public function testConcurrentFileRequestsWithMaxConcurrentOne(): void
    {
        // Prepare two local files
        $tmp1 = tempnam(sys_get_temp_dir(), 'curl-a-') ?: sys_get_temp_dir() . '/curl-a-' . uniqid();
        $tmp2 = tempnam(sys_get_temp_dir(), 'curl-b-') ?: sys_get_temp_dir() . '/curl-b-' . uniqid();
        file_put_contents($tmp1, 'FILE_A');
        file_put_contents($tmp2, 'FILE_B');

        $scheduler = new FiberScheduler();
        // maxConcurrent = 1 forces sequential handling via the shared multi loop
        $client = new CurlMultiHttpClient(null, 0.001, 0, 0.0, [], 1);

        $req1 = new Request('GET', 'file://' . $tmp1);
        $req2 = new Request('GET', 'file://' . $tmp2);
        $t1 = $client->sendAsync($req1);
        $t2 = $client->sendAsync($req2);

        [$r1, $r2] = $scheduler->all([$t1, $t2]);
        $this->assertSame(200, $r1->getStatusCode());
        $this->assertSame(200, $r2->getStatusCode());
        $this->assertSame('FILE_A', (string) $r1->getBody());
        $this->assertSame('FILE_B', (string) $r2->getBody());
        // Ensure no header lines leak into body for file:// protocol
        $this->assertStringNotContainsString('Content-Length:', (string)$r1->getBody());
        $this->assertStringNotContainsString('Last-Modified:', (string)$r1->getBody());
        $this->assertStringNotContainsString('Accept-Ranges:', (string)$r1->getBody());
        $this->assertStringNotContainsString('Content-Length:', (string)$r2->getBody());
        $this->assertStringNotContainsString('Last-Modified:', (string)$r2->getBody());
        $this->assertStringNotContainsString('Accept-Ranges:', (string)$r2->getBody());

        @unlink($tmp1);
        @unlink($tmp2);
    }
}
