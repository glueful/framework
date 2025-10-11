<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions;

use Glueful\Auth\Attributes\RequiresPermission;
use Glueful\Auth\Attributes\RequiresRole;

#[RequiresRole('admin')]
class TestControllerWithAttributes
{
    #[RequiresPermission('posts.create')]
    public function store(): void
    {
        // Test method
    }
}

