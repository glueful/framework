<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Http\Exceptions;

use Glueful\Http\Exceptions\Handler;
use Glueful\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class ChannelTestPaymentException extends \RuntimeException
{
}

class ChannelTestCardDeclinedException extends ChannelTestPaymentException
{
}

final class ChannelTestHandler extends Handler
{
    public function channelFor(\Throwable $e): string
    {
        return $this->resolveLogChannel($e);
    }
}

/** An app's mapChannel() decision wins over the framework-origin heuristic. */
final class HandlerChannelMappingTest extends TestCase
{
    public function testAppMappingWinsOverTheFrameworkHeuristic(): void
    {
        $handler = (new ChannelTestHandler(debug: false))
            ->mapChannel(ChannelTestPaymentException::class, 'payments');

        // Thrown here, every trace frame is a Glueful\ class — the heuristic alone says 'framework'.
        self::assertSame('payments', $handler->channelFor(new ChannelTestPaymentException('x')));
    }

    public function testUnmappedExceptionsStillUseTheHeuristic(): void
    {
        $handler = new ChannelTestHandler(debug: false);

        self::assertSame('framework', $handler->channelFor(new ChannelTestPaymentException('x')));
    }

    public function testTheMostSpecificAppMappingWinsRegardlessOfRegistrationOrder(): void
    {
        $parentFirst = (new ChannelTestHandler(debug: false))
            ->mapChannel(ChannelTestPaymentException::class, 'payments')
            ->mapChannel(ChannelTestCardDeclinedException::class, 'declines');
        $childFirst = (new ChannelTestHandler(debug: false))
            ->mapChannel(ChannelTestCardDeclinedException::class, 'declines')
            ->mapChannel(ChannelTestPaymentException::class, 'payments');

        foreach ([$parentFirst, $childFirst] as $handler) {
            self::assertSame('declines', $handler->channelFor(new ChannelTestCardDeclinedException('x')));
            self::assertSame('payments', $handler->channelFor(new ChannelTestPaymentException('x')));
        }
    }

    public function testAnAppMappingCanReassignAFrameworkDefault(): void
    {
        $handler = (new ChannelTestHandler(debug: false))
            ->mapChannel(ValidationException::class, 'input');

        self::assertSame('input', $handler->channelFor(ValidationException::forField('a', 'b')));
    }
}
