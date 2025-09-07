<?php

declare(strict_types=1);

namespace Glueful\Validation;

/**
 * Collection Builder
 *
 * Handles validation of array/collection fields.
 */
class CollectionBuilder
{
    /** @var array<string, mixed> Field constraints */
    private array $fields;

    /**
     * Constructor
     *
     * @param array<string, mixed> $fields Field constraints
     */
    public function __construct(array $fields)
    {
        $this->fields = $fields;
    }

    /**
     * Build collection constraint configuration
     *
     * @return array<string, mixed> Collection constraint configuration
     */
    public function build(): array
    {
        return [
            'type' => 'collection',
            'fields' => $this->fields,
        ];
    }
}
