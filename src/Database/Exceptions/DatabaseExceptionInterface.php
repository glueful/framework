<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

/**
 * Contract for classified database failures.
 *
 * All classified exceptions also extend \PDOException, so existing
 * catch (\PDOException) sites keep matching them.
 */
interface DatabaseExceptionInterface extends \Throwable
{
    public function sqlState(): ?string;

    public function driverCode(): int|string|null;

    public function driver(): string;
}
