<?php

declare(strict_types=1);

namespace Glueful\Serialization;

use Glueful\Serialization\Context\SerializationContext;

final class Serializer
{
    public function normalize(mixed $data, ?SerializationContext $context = null): mixed
    {
        if ($data === null) {
            return null;
        }

        // Scalars
        if (is_scalar($data)) {
            return $data;
        }

        // DateTime
        if ($data instanceof \DateTimeInterface) {
            return $data->format('c');
        }

        // JsonSerializable
        if ($data instanceof \JsonSerializable) {
            return $data->jsonSerialize();
        }

        // toArray convention
        if (is_object($data) && method_exists($data, 'toArray')) {
            /** @var mixed $arr */
            $arr = $data->toArray();
            return $this->normalize($arr, $context);
        }

        // Arrays and iterables
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                $out[$k] = $this->normalize($v, $context);
            }
            return $out;
        }
        if ($data instanceof \Traversable) {
            $out = [];
            foreach ($data as $k => $v) {
                $out[$k] = $this->normalize($v, $context);
            }
            return $out;
        }

        // Fallback: string-cast
        if (is_object($data)) {
            return method_exists($data, '__toString') ? (string)$data : get_class($data);
        }
        return $data;
    }
}
