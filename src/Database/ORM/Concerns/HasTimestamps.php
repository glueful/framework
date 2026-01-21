<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Concerns;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Has Timestamps Trait
 *
 * Provides automatic timestamp management for ORM models.
 * Handles created_at and updated_at columns automatically during
 * insert and update operations.
 */
trait HasTimestamps
{
    /**
     * Indicates if the model should be timestamped
     */
    public bool $timestamps = true;

    /**
     * The name of the "created at" column
     */
    public const CREATED_AT = 'created_at';

    /**
     * The name of the "updated at" column
     */
    public const UPDATED_AT = 'updated_at';

    /**
     * The storage format of the model's date columns
     */
    protected string $dateFormat = 'Y-m-d H:i:s';

    /**
     * Determine if the model uses timestamps
     *
     * @return bool
     */
    public function usesTimestamps(): bool
    {
        return $this->timestamps;
    }

    /**
     * Update the model's timestamps
     *
     * @return static
     */
    public function touch(): static
    {
        if (!$this->usesTimestamps()) {
            return $this;
        }

        $this->updateTimestamps();

        return $this;
    }

    /**
     * Update the creation and update timestamps
     *
     * @return void
     */
    protected function updateTimestamps(): void
    {
        $time = $this->freshTimestamp();

        $updatedAtColumn = $this->getUpdatedAtColumn();
        if ($updatedAtColumn !== null && !$this->isDirty($updatedAtColumn)) {
            $this->setAttribute($updatedAtColumn, $time);
        }

        $createdAtColumn = $this->getCreatedAtColumn();
        if (!$this->exists && $createdAtColumn !== null && !$this->isDirty($createdAtColumn)) {
            $this->setAttribute($createdAtColumn, $time);
        }
    }

    /**
     * Set the value of the "created at" attribute
     *
     * @param mixed $value
     * @return static
     */
    public function setCreatedAt(mixed $value): static
    {
        $column = $this->getCreatedAtColumn();

        if ($column !== null) {
            $this->setAttribute($column, $value);
        }

        return $this;
    }

    /**
     * Set the value of the "updated at" attribute
     *
     * @param mixed $value
     * @return static
     */
    public function setUpdatedAt(mixed $value): static
    {
        $column = $this->getUpdatedAtColumn();

        if ($column !== null) {
            $this->setAttribute($column, $value);
        }

        return $this;
    }

    /**
     * Get a fresh timestamp for the model
     *
     * @return DateTimeImmutable
     */
    public function freshTimestamp(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    /**
     * Get a fresh timestamp string for the model
     *
     * @return string
     */
    public function freshTimestampString(): string
    {
        return $this->freshTimestamp()->format($this->getDateFormat());
    }

    /**
     * Get the name of the "created at" column
     *
     * @return string|null
     */
    public function getCreatedAtColumn(): ?string
    {
        return static::CREATED_AT;
    }

    /**
     * Get the name of the "updated at" column
     *
     * @return string|null
     */
    public function getUpdatedAtColumn(): ?string
    {
        return static::UPDATED_AT;
    }

    /**
     * Get the format for database stored dates
     *
     * @return string
     */
    public function getDateFormat(): string
    {
        return $this->dateFormat;
    }

    /**
     * Set the date format used by the model
     *
     * @param string $format
     * @return static
     */
    public function setDateFormat(string $format): static
    {
        $this->dateFormat = $format;

        return $this;
    }

    /**
     * Convert a DateTimeInterface to a storable string
     *
     * @param DateTimeInterface $date
     * @return string
     */
    public function fromDateTime(DateTimeInterface $date): string
    {
        return $date->format($this->getDateFormat());
    }
}
