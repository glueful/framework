<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Events;

/**
 * Model Updating Event
 *
 * Dispatched before an existing model is updated in the database.
 * Listeners can modify the model or stop propagation to prevent the update.
 */
class ModelUpdating extends ModelEvent
{
}
