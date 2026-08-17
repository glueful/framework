<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

enum ReadinessState: string
{
    case Ready = 'ready';
    case Pending = 'pending';
    case Divergent = 'divergent';
}
