<?php

declare(strict_types=1);

namespace Glueful\Events\Traits;

/**
 * Event Helpers Trait
 *
 * Convenience trait that includes the most commonly used event traits.
 * Use this for simple events that need basic functionality.
 */
trait EventHelpers
{
    use Dispatchable;
    use Timestampable;
    use Serializable;
}
