<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Concerns;

use Glueful\Database\ORM\Events\ModelCreated;
use Glueful\Database\ORM\Events\ModelCreating;
use Glueful\Database\ORM\Events\ModelDeleted;
use Glueful\Database\ORM\Events\ModelDeleting;
use Glueful\Database\ORM\Events\ModelEvent;
use Glueful\Database\ORM\Events\ModelRetrieved;
use Glueful\Database\ORM\Events\ModelSaved;
use Glueful\Database\ORM\Events\ModelSaving;
use Glueful\Database\ORM\Events\ModelUpdated;
use Glueful\Database\ORM\Events\ModelUpdating;
use Glueful\Events\Event;

/**
 * Has Events Trait
 *
 * Provides model lifecycle event functionality for ORM models.
 * Integrates with the framework's PSR-14 event dispatcher.
 *
 * Events are fired in this order:
 * - retrieved: After a model is loaded from the database
 * - saving: Before any save operation (create or update)
 * - creating: Before a new model is inserted (first save)
 * - created: After a new model is inserted
 * - updating: Before an existing model is updated
 * - updated: After an existing model is updated
 * - saved: After any save operation
 * - deleting: Before a model is deleted
 * - deleted: After a model is deleted
 */
trait HasEvents
{
    /**
     * User registered model events using closures
     *
     * @var array<string, array<callable>>
     */
    protected static array $modelEventCallbacks = [];

    /**
     * Whether to dispatch events for this model
     */
    protected bool $dispatchesEvents = true;

    /**
     * The event map for the model (class-based events)
     *
     * @var array<string, class-string<ModelEvent>>
     */
    protected array $dispatchesModelEvents = [];

    /**
     * Fire a model event
     *
     * @param string $event The event name (creating, created, etc.)
     * @return bool Returns false if propagation was stopped
     */
    protected function fireModelEvent(string $event): bool
    {
        if (!$this->dispatchesEvents) {
            return true;
        }

        // Get the event class for this event type
        $eventClass = $this->getEventClass($event);

        if ($eventClass === null) {
            return true;
        }

        // Create and dispatch the event
        $eventInstance = new $eventClass($this);

        try {
            Event::dispatch($eventInstance);
        } catch (\LogicException) {
            // Event facade not bootstrapped - skip event dispatch
            // This allows models to work even if the event system isn't set up
            return true;
        }

        // Check if propagation was stopped
        return !$eventInstance->isPropagationStopped();
    }

    /**
     * Get the event class for a given event type
     *
     * @param string $event
     * @return class-string<ModelEvent>|null
     */
    protected function getEventClass(string $event): ?string
    {
        // Check for custom event class mapping
        if (isset($this->dispatchesModelEvents[$event])) {
            return $this->dispatchesModelEvents[$event];
        }

        // Default event classes
        return match ($event) {
            'retrieved' => ModelRetrieved::class,
            'creating' => ModelCreating::class,
            'created' => ModelCreated::class,
            'updating' => ModelUpdating::class,
            'updated' => ModelUpdated::class,
            'saving' => ModelSaving::class,
            'saved' => ModelSaved::class,
            'deleting' => ModelDeleting::class,
            'deleted' => ModelDeleted::class,
            default => null,
        };
    }

    /**
     * Fire the retrieved event for the model
     *
     * @return void
     */
    protected function fireRetrievedEvent(): void
    {
        $this->fireModelEvent('retrieved');
    }

    /**
     * Fire the saving event for the model
     *
     * @return bool
     */
    protected function fireSavingEvent(): bool
    {
        return $this->fireModelEvent('saving');
    }

    /**
     * Fire the saved event for the model
     *
     * @return void
     */
    protected function fireSavedEvent(): void
    {
        $this->fireModelEvent('saved');
    }

    /**
     * Fire the creating event for the model
     *
     * @return bool
     */
    protected function fireCreatingEvent(): bool
    {
        return $this->fireModelEvent('creating');
    }

    /**
     * Fire the created event for the model
     *
     * @return void
     */
    protected function fireCreatedEvent(): void
    {
        $this->fireModelEvent('created');
    }

    /**
     * Fire the updating event for the model
     *
     * @return bool
     */
    protected function fireUpdatingEvent(): bool
    {
        return $this->fireModelEvent('updating');
    }

    /**
     * Fire the updated event for the model
     *
     * @return void
     */
    protected function fireUpdatedEvent(): void
    {
        $this->fireModelEvent('updated');
    }

    /**
     * Fire the deleting event for the model
     *
     * @return bool
     */
    protected function fireDeletingEvent(): bool
    {
        return $this->fireModelEvent('deleting');
    }

    /**
     * Fire the deleted event for the model
     *
     * @return void
     */
    protected function fireDeletedEvent(): void
    {
        $this->fireModelEvent('deleted');
    }

    /**
     * Register a "creating" model event listener
     *
     * @param callable $callback
     * @return void
     */
    public static function creating(callable $callback): void
    {
        static::registerModelEvent('creating', $callback);
    }

    /**
     * Register a "created" model event listener
     *
     * @param callable $callback
     * @return void
     */
    public static function created(callable $callback): void
    {
        static::registerModelEvent('created', $callback);
    }

    /**
     * Register an "updating" model event listener
     *
     * @param callable $callback
     * @return void
     */
    public static function updating(callable $callback): void
    {
        static::registerModelEvent('updating', $callback);
    }

    /**
     * Register an "updated" model event listener
     *
     * @param callable $callback
     * @return void
     */
    public static function updated(callable $callback): void
    {
        static::registerModelEvent('updated', $callback);
    }

    /**
     * Register a "saving" model event listener
     *
     * @param callable $callback
     * @return void
     */
    public static function saving(callable $callback): void
    {
        static::registerModelEvent('saving', $callback);
    }

    /**
     * Register a "saved" model event listener
     *
     * @param callable $callback
     * @return void
     */
    public static function saved(callable $callback): void
    {
        static::registerModelEvent('saved', $callback);
    }

    /**
     * Register a "deleting" model event listener
     *
     * @param callable $callback
     * @return void
     */
    public static function deleting(callable $callback): void
    {
        static::registerModelEvent('deleting', $callback);
    }

    /**
     * Register a "deleted" model event listener
     *
     * @param callable $callback
     * @return void
     */
    public static function deleted(callable $callback): void
    {
        static::registerModelEvent('deleted', $callback);
    }

    /**
     * Register a "retrieved" model event listener
     *
     * @param callable $callback
     * @return void
     */
    public static function retrieved(callable $callback): void
    {
        static::registerModelEvent('retrieved', $callback);
    }

    /**
     * Register a model event callback
     *
     * @param string $event
     * @param callable $callback
     * @return void
     */
    protected static function registerModelEvent(string $event, callable $callback): void
    {
        $eventClass = (new static())->getEventClass($event);

        if ($eventClass === null) {
            return;
        }

        try {
            Event::listen($eventClass, function (ModelEvent $e) use ($callback): void {
                // Only call callback for this specific model class
                if ($e->getModel() instanceof static) {
                    $callback($e->getModel());
                }
            });
        } catch (\LogicException) {
            // Event facade not bootstrapped - store for later
            static::$modelEventCallbacks[$event][] = $callback;
        }
    }

    /**
     * Disable event dispatching for this model instance
     *
     * @return static
     */
    public function withoutEvents(): static
    {
        $this->dispatchesEvents = false;

        return $this;
    }

    /**
     * Enable event dispatching for this model instance
     *
     * @return static
     */
    public function withEvents(): static
    {
        $this->dispatchesEvents = true;

        return $this;
    }

    /**
     * Execute a callback without firing events
     *
     * @param callable $callback
     * @return mixed
     */
    public static function withoutEventsStatic(callable $callback): mixed
    {
        $instance = new static();
        $instance->dispatchesEvents = false;

        return $callback($instance);
    }
}
