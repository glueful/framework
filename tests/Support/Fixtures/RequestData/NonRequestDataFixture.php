<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Fixtures\RequestData;

/**
 * A plain class that intentionally does NOT implement RequestData.
 * Used to verify the hydrator rejects #[ArrayOf(NonRequestData)] on a request DTO.
 */
final class NonRequestDataFixture
{
    public function __construct(
        public string $value = '',
    ) {
    }
}
