<?php

declare(strict_types=1);

namespace Glueful\Container\Exception;

class NotFoundException extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface
{
}
