<?php

declare(strict_types=1);

namespace Glueful\Contracts;

use Glueful\Bootstrap\ApplicationContext;

interface ContextAwareInterface
{
    public function getContext(): ?ApplicationContext;
}
