<?php

declare(strict_types=1);

namespace Glueful\Extensions\Install;

/** The install kill-switch (extensions.install.enabled) is off. Maps to HTTP 403. */
final class InstallDisabledException extends \RuntimeException
{
}
