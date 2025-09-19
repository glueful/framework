<?php

declare(strict_types=1);

namespace Glueful\Events\Traits;

trait Serializable
{
    public function __serialize(): array
    {
        $data = [];
        $reflection = new \ReflectionClass($this);
        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $name = $property->getName();
            $value = $property->getValue($this);
            $data[$name] = $this->serializeValue($value);
        }
        return $data;
    }

    public function __unserialize(array $data): void
    {
        $reflection = new \ReflectionClass($this);
        foreach ($data as $name => $value) {
            if ($reflection->hasProperty($name)) {
                $property = $reflection->getProperty($name);
                $property->setAccessible(true);
                $property->setValue($this, $this->unserializeValue($value));
            }
        }
    }

    public function toArray(): array
    {
        return $this->__serialize();
    }

    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    public static function fromArray(array $data): static
    {
        $instance = new static(...[]);
        $instance->__unserialize($data);
        return $instance;
    }

    private function serializeValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return [
                '__type' => 'datetime',
                '__value' => $value->format(\DateTimeInterface::ATOM),
                '__timezone' => $value->getTimezone()->getName()
            ];
        }
        if (is_object($value) && method_exists($value, '__serialize')) {
            return [
                '__type' => 'serializable',
                '__class' => get_class($value),
                '__value' => $value->__serialize()
            ];
        }
        return $value;
    }

    private function unserializeValue(mixed $value): mixed
    {
        if (is_array($value) && isset($value['__type'])) {
            switch ($value['__type']) {
                case 'datetime':
                    $timezone = new \DateTimeZone($value['__timezone']);
                    return new \DateTimeImmutable($value['__value'], $timezone);
                case 'serializable':
                    $class = $value['__class'];
                    if (class_exists($class)) {
                        $instance = new $class();
                        if (method_exists($instance, '__unserialize')) {
                            $instance->__unserialize($value['__value']);
                            return $instance;
                        }
                    }
                    break;
            }
        }
        return $value;
    }
}
