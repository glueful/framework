<?php

declare(strict_types=1);

namespace Glueful\Extensions\Install;

/** Package failed the name-grammar / vendor-prefix / catalog-membership allowlist. Maps to HTTP 422. */
final class PackageNotAllowedException extends \RuntimeException
{
}
