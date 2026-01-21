<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Domain;

use Glueful\Http\Exceptions\HttpException;
use Throwable;

/**
 * Model Not Found Exception
 *
 * Thrown when an ORM model or database record cannot be found.
 * Provides additional context about the model class and the IDs
 * that were searched for.
 *
 * This is a domain-specific 404 exception that carries model metadata,
 * making it easier to generate meaningful error messages and debug issues.
 *
 * @example
 * // Basic usage
 * throw new ModelNotFoundException('User not found');
 *
 * @example
 * // With model context
 * throw (new ModelNotFoundException())
 *     ->setModel(User::class, $id);
 *
 * @example
 * // Multiple IDs not found
 * throw (new ModelNotFoundException())
 *     ->setModel(Order::class, [1, 2, 3]);
 */
class ModelNotFoundException extends HttpException
{
    /**
     * The model class name
     *
     * @var string
     */
    protected string $model = '';

    /**
     * The IDs that were not found
     *
     * @var array<mixed>
     */
    protected array $ids = [];

    /**
     * Create a new Model Not Found exception
     *
     * @param string $message Error message
     * @param Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message = 'Resource not found',
        ?Throwable $previous = null
    ) {
        parent::__construct(404, $message, [], 0, $previous);
    }

    /**
     * Set the affected model and IDs
     *
     * Automatically generates an informative error message based on
     * the model class name and IDs provided.
     *
     * @param class-string $model The model class name
     * @param mixed $ids The ID(s) that were not found
     * @return static
     */
    public function setModel(string $model, mixed $ids = []): static
    {
        $this->model = $model;
        $this->ids = is_array($ids) ? $ids : [$ids];

        // Extract short class name for message
        $shortName = class_basename($model);
        $this->message = "No query results for model [{$shortName}]";

        if ($this->ids !== []) {
            $idList = implode(', ', array_map(
                fn ($id) => is_scalar($id) ? (string) $id : gettype($id),
                $this->ids
            ));
            $this->message .= ' with ID(s): ' . $idList;
        }

        return $this;
    }

    /**
     * Get the model class name
     *
     * @return string The fully qualified model class name
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Get the IDs that were not found
     *
     * @return array<mixed>
     */
    public function getIds(): array
    {
        return $this->ids;
    }
}

/**
 * Get the class "basename" of a class string
 *
 * @param string $class
 * @return string
 */
function class_basename(string $class): string
{
    $pos = strrpos($class, '\\');

    return $pos === false ? $class : substr($class, $pos + 1);
}
