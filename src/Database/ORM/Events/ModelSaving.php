<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Events;

/**
 * Model Saving Event
 *
 * Dispatched before a model is saved (either created or updated).
 * This event fires before ModelCreating or ModelUpdating.
 * Listeners can modify the model or stop propagation to prevent saving.
 */
class ModelSaving extends ModelEvent
{
}
