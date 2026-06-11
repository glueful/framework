<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Container;

use Glueful\Container\Loader\DefaultServicesLoader;
use PHPUnit\Framework\TestCase;

interface GuardSampleInterface
{
}

abstract class GuardSampleAbstract
{
}

final class GuardSampleConcrete implements GuardSampleInterface
{
}

/**
 * A DSL service whose id is an interface/abstract with no 'class'/'factory'/'autowire' would
 * load green and only fatal ("Cannot instantiate interface") at first resolution -- possibly
 * in production, on a cold path. The loader must reject it at LOAD time, naming the id.
 */
final class NonInstantiableServiceGuardTest extends TestCase
{
    public function test_interface_id_without_class_is_rejected_at_load(): void
    {
        $loader = new DefaultServicesLoader();

        try {
            $loader->load([GuardSampleInterface::class => ['shared' => true]], 'Test\\Provider', false);
            self::fail('expected an interface-keyed service with no class to be rejected at load');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString(GuardSampleInterface::class, $e->getMessage());
        }
    }

    public function test_abstract_id_without_class_is_rejected_at_load(): void
    {
        $loader = new DefaultServicesLoader();

        $this->expectException(\InvalidArgumentException::class);
        $loader->load([GuardSampleAbstract::class => ['shared' => true]], 'Test\\Provider', false);
    }

    public function test_interface_bound_to_concrete_class_is_accepted(): void
    {
        $loader = new DefaultServicesLoader();

        $out = $loader->load(
            [GuardSampleInterface::class => ['class' => GuardSampleConcrete::class]],
            'Test\\Provider',
            false
        );

        self::assertArrayHasKey(GuardSampleInterface::class, $out);
    }

    public function test_concrete_class_id_is_accepted(): void
    {
        $loader = new DefaultServicesLoader();

        $out = $loader->load([GuardSampleConcrete::class => ['shared' => true]], 'Test\\Provider', false);

        self::assertArrayHasKey(GuardSampleConcrete::class, $out);
    }
}
