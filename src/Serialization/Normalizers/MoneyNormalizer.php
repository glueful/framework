<?php

declare(strict_types=1);

namespace Glueful\Serialization\Normalizers;

use Glueful\Serialization\Money;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Money Normalizer
 *
 * Handles serialization of Money objects with amount, currency,
 * and formatted representation.
 */
class MoneyNormalizer implements NormalizerInterface, DenormalizerInterface
{
    /**
     * Normalize Money object
     *
     * @param mixed $object
     * @param string|null $format
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     * @phpstan-ignore-next-line
     */
    public function normalize(
        mixed $object,
        ?string $format = null,
        array $context = []
    ): array {
        if (!$object instanceof Money) {
            throw new \InvalidArgumentException('Object must be an instance of Money');
        }

        return [
            'amount' => $object->getAmount(),
            'currency' => $object->getCurrency(),
            'formatted' => $object->format(),
            'display' => $object->getDisplayAmount(),
        ];
    }

    /**
     * Denormalize data to Money object
     *
     * @param mixed $data
     * @param string $type
     * @param string|null $format
     * @param array $context
     *  @phpstan-ignore-next-line
     */
    public function denormalize(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = []
    ): Money {
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Data must be an array');
        }

        $amount = $data['amount'] ?? 0;
        $currency = $data['currency'] ?? 'USD';

        return new Money($amount, $currency);
    }

    /**
     * Check if normalization is supported
     *
     * @param mixed $data
     * @param string|null $format
     * @param array<string, mixed> $context
     * @phpstan-ignore-next-line
     */
    public function supportsNormalization(
        mixed $data,
        ?string $format = null,
        array $context = []
    ): bool {
        return $data instanceof Money;
    }

    /**
     * Check if denormalization is supported
     *
     * @param mixed $data
     * @param string $type
     * @param string|null $format
     * @param array<string, mixed> $context
     * @phpstan-ignore-next-line
     */
    public function supportsDenormalization(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = []
    ): bool {
        return $type === Money::class || is_subclass_of($type, Money::class);
    }

    /**
     * Get supported types for this normalizer
     *
     * @return array<string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            Money::class => true,
        ];
    }
}
