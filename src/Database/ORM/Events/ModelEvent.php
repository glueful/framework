<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Events;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Base Model Event
 *
 * Base class for all ORM model events. Provides common functionality
 * for accessing the model instance that triggered the event.
 */
abstract class ModelEvent extends BaseEvent
{
    /**
     * Create a new model event instance
     *
     * @param object $model The model instance
     */
    public function __construct(
        protected readonly object $model
    ) {
        parent::__construct();
    }

    /**
     * Get the model instance
     *
     * @return object
     */
    public function getModel(): object
    {
        return $this->model;
    }

    /**
     * Get the model class name
     *
     * @return string
     */
    public function getModelClass(): string
    {
        return $this->model::class;
    }
}
