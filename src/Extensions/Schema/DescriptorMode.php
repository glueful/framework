<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

enum DescriptorMode: string
{
    case Core = 'core';
    case OnEnable = 'on_enable';
}
