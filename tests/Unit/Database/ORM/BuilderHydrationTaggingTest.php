<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\ORM;

use Glueful\Database\ORM\Model;
use PHPUnit\Framework\TestCase;
use Glueful\Tests\Support\Stubs\HydrationTaggingTestModel;
use Glueful\Tests\Support\Traits\ResetsLazyLoading;

class BuilderHydrationTaggingTest extends TestCase
{
    use ResetsLazyLoading;

    public function testSetLoadedFromCollectionFlagPersists(): void
    {
        $model = new HydrationTaggingTestModel();
        $this->assertFalse($model->wasLoadedFromCollection());

        $model->setLoadedFromCollection(true);
        $this->assertTrue($model->wasLoadedFromCollection());

        $model->setLoadedFromCollection(false);
        $this->assertFalse($model->wasLoadedFromCollection());
    }

    public function testLazyLoadingEnabledControlsTaggingDecision(): void
    {
        // This test verifies the GUARD logic that Builder::hydrate() uses —
        // namely Model::lazyLoadingEnabled(). It does NOT exercise the real
        // hydrate(); the integration test in Task 11 does that.
        Model::preventLazyLoading('off');
        $this->assertFalse(Model::lazyLoadingEnabled());

        Model::preventLazyLoading('warn');
        $this->assertTrue(Model::lazyLoadingEnabled());

        Model::preventLazyLoading('strict');
        $this->assertTrue(Model::lazyLoadingEnabled());
    }
}
