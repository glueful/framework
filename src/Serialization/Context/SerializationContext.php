<?php
declare(strict_types=1);

namespace Glueful\Serialization\Context;

final class SerializationContext
{
    /** @var string[] */
    private array $groups = [];

    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }

    /**
     * @param string[] $groups
     */
    public function withGroups(array $groups): self
    {
        $this->groups = array_values(array_unique($groups));
        return $this;
    }

    /**
     * @return string[]
     */
    public function groups(): array
    {
        return $this->groups;
    }
}

