<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Events;

/**
 * Model Deleting Event
 *
 * Dispatched before a model is deleted from the database.
 * Listeners can stop propagation to prevent deletion.
 */
class ModelDeleting extends ModelEvent
{
}
