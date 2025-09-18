<?php

declare(strict_types=1);

namespace Glueful\Tests\Core;

use PHPUnit\Framework\TestCase;
use Glueful\Framework;

final class FrameworkBootTest extends TestCase
{
    public function testFrameworkBootsAndExposesContainer(): void
    {
        $fw = Framework::create(getcwd());
        $app = $fw->boot(allowReboot: true);

        $this->assertTrue($fw->isBooted());
        $this->assertNotNull($app->getContainer());
    }
}
