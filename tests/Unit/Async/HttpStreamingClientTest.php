<?php

declare(strict_types=1);

use Glueful\Async\FiberScheduler;
use Glueful\Async\Http\CurlMultiHttpClient;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class HttpStreamingClientTest extends TestCase
{
    public function testSendAsyncStreamInvokesCallback(): void
    {
        // Prepare local file to "stream"
        $tmp = tempnam(sys_get_temp_dir(), 'curl-stream-') ?: sys_get_temp_dir() . '/curl-stream-' . uniqid();
        file_put_contents($tmp, str_repeat('A', 1024) . str_repeat('B', 1024));

        $scheduler = new FiberScheduler();
        $client = new CurlMultiHttpClient();
        $request = new Request('GET', 'file://' . $tmp);

        $chunks = [];
        $task = $client->sendAsyncStream($request, function (string $chunk) use (&$chunks) {
            $chunks[] = $chunk;
        });

        [$response] = $scheduler->all([$task]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(str_repeat('A', 1024) . str_repeat('B', 1024), (string)$response->getBody());
        // Ensure no header lines leak into body across environments (e.g., file:// with pseudo-headers)
        $this->assertStringNotContainsString('Content-Length:', (string)$response->getBody());
        $this->assertStringNotContainsString('Last-Modified:', (string)$response->getBody());
        $this->assertStringNotContainsString('Accept-Ranges:', (string)$response->getBody());
        $this->assertNotEmpty($chunks);

        @unlink($tmp);
    }
}
