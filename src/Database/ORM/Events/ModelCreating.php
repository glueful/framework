<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Events;

/**
 * Model Creating Event
 *
 * Dispatched before a new model is saved to the database for the first time.
 * Listeners can modify the model or stop propagation to prevent creation.
 */
class ModelCreating extends ModelEvent
{
}
