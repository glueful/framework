<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\FieldSelection;

use Glueful\Support\FieldSelection\FieldSelector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \Glueful\Support\FieldSelection\FieldSelector
 */
final class FieldSelectorRequestTest extends TestCase
{
    public function testArrayValuedFieldsParamIsTreatedAsNoSelectionInsteadOfThrowing(): void
    {
        // ?fields[]=a&fields[]=b — Symfony's InputBag::get() would throw a BadRequestException here.
        // Field selection is a scalar syntax, so an array value is simply not a selection.
        $request = new Request(['fields' => ['a', 'b']]);

        $selector = FieldSelector::fromRequest($request);

        self::assertTrue($selector->empty());
    }

    public function testArrayValuedExpandParamIsTreatedAsNoSelection(): void
    {
        $request = new Request(['expand' => ['posts']]);

        $selector = FieldSelector::fromRequest($request);

        self::assertTrue($selector->empty());
    }

    public function testArrayValuedParamDoesNotThrowOnAdvancedFactory(): void
    {
        $request = new Request(['fields' => ['a'], 'expand' => ['b']]);

        $selector = FieldSelector::fromRequestAdvanced($request);

        self::assertTrue($selector->empty());
    }

    public function testScalarFieldsParamStillParsesNormally(): void
    {
        $request = new Request(['fields' => 'id,name']);

        $selector = FieldSelector::fromRequest($request);

        self::assertFalse($selector->empty());
        self::assertTrue($selector->requested('id'));
        self::assertTrue($selector->requested('name'));
    }

    public function testNoParamsIsEmptySelection(): void
    {
        $selector = FieldSelector::fromRequest(new Request());

        self::assertTrue($selector->empty());
    }
}
