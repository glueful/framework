<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Events;

/**
 * Model Saved Event
 *
 * Dispatched after a model has been saved (either created or updated).
 * This event fires after ModelCreated or ModelUpdated.
 */
class ModelSaved extends ModelEvent
{
}
