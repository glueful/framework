<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit;

use Glueful\Logging\StandardLogProcessor;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class StandardLogProcessorTest extends TestCase
{
    public function testProcessorAddsExpectedFields(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);
        $logger->pushProcessor(new StandardLogProcessor('testing', '1.2.3', fn() => '42'));

        $logger->log(Level::Info, 'hello');

        $this->assertTrue($handler->hasInfoRecords());
        $records = $handler->getRecords();
        $first = $records[0];

        // Monolog 3 uses LogRecord objects
        $extra = $first->extra ?? [];
        $this->assertSame('testing', $extra['environment'] ?? null);
        $this->assertSame('1.2.3', $extra['framework_version'] ?? null);
        $this->assertArrayHasKey('timestamp', $extra);
        $this->assertArrayHasKey('memory_usage', $extra);
        $this->assertArrayHasKey('peak_memory', $extra);
        $this->assertArrayHasKey('process_id', $extra);
        $this->assertSame('42', $extra['user_id'] ?? null);
    }
}
