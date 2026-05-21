<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\ORM;

use Glueful\Database\ORM\Concerns\PreventsLazyLoading;
use Glueful\Database\ORM\Model;
use Glueful\Tests\Support\Stubs\HydrationTaggingTestModel;
use PHPUnit\Framework\TestCase;

/**
 * Anonymous host class that uses the trait under test. Static properties
 * on a trait are per-using-class in PHP, so TraitHost has its own copy
 * independent of Model. Tests reset TraitHost state directly in tearDown.
 */
class TraitHost
{
    use PreventsLazyLoading;
}

class PreventsLazyLoadingTraitTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset BOTH the local TraitHost and Model — each class that uses
        // the trait owns its own copy of the static state. Later tasks
        // (Task 7+) add tests that mutate Model state, so reset both here.
        TraitHost::resetLazyLoadingState();
        if (method_exists(\Glueful\Database\ORM\Model::class, 'resetLazyLoadingState')) {
            \Glueful\Database\ORM\Model::resetLazyLoadingState();
        }
        parent::tearDown();
    }

    public function testDefaultModeIsOff(): void
    {
        $this->assertFalse(TraitHost::lazyLoadingEnabled());
    }

    public function testPreventLazyLoadingSetsMode(): void
    {
        TraitHost::preventLazyLoading('warn');
        $this->assertTrue(TraitHost::lazyLoadingEnabled());
    }

    public function testLazyLoadingEnabledIsFalseForOffMode(): void
    {
        TraitHost::preventLazyLoading('off');
        $this->assertFalse(TraitHost::lazyLoadingEnabled());
    }

    public function testResetClearsMode(): void
    {
        TraitHost::preventLazyLoading('strict');
        TraitHost::resetLazyLoadingState();
        $this->assertFalse(TraitHost::lazyLoadingEnabled());
    }

    public function testAutoResolvesToWarnInDevelopment(): void
    {
        $prev = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = 'development';
        try {
            TraitHost::preventLazyLoading('auto');
            $this->assertTrue(TraitHost::lazyLoadingEnabled());
            $this->assertSame('warn', TraitHost::resolvedGlobalMode());
        } finally {
            if ($prev === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $prev;
            }
        }
    }

    public function testAutoResolvesToOffOutsideDevelopment(): void
    {
        $prev = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = 'production';
        try {
            TraitHost::preventLazyLoading('auto');
            $this->assertFalse(TraitHost::lazyLoadingEnabled());
        } finally {
            if ($prev === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $prev;
            }
        }
    }

    public function testWarnModeLogsViaErrorLog(): void
    {
        Model::preventLazyLoading('warn');

        // Capture error_log output by routing to a temp file
        $tmp = tempnam(sys_get_temp_dir(), 'glueful-n1-');
        $prevLog = ini_set('error_log', $tmp);
        try {
            $model = new HydrationTaggingTestModel();
            $model->setLoadedFromCollection(true);

            // Use reflection to invoke the protected handler
            $ref = new \ReflectionMethod($model, 'handleLazyLoadingViolation');
            $ref->setAccessible(true);
            $ref->invoke($model, 'posts');

            $logged = file_get_contents($tmp);
            $this->assertStringContainsString('[GLUEFUL-N+1]', $logged);
            $this->assertStringContainsString('posts', $logged);
        } finally {
            ini_set('error_log', $prevLog);
            @unlink($tmp);
        }
    }

    public function testWarnModeDedupesWithinRequest(): void
    {
        Model::preventLazyLoading('warn');

        $tmp = tempnam(sys_get_temp_dir(), 'glueful-n1-');
        $prevLog = ini_set('error_log', $tmp);
        try {
            $model = new HydrationTaggingTestModel();
            $model->setLoadedFromCollection(true);

            $ref = new \ReflectionMethod($model, 'handleLazyLoadingViolation');
            $ref->setAccessible(true);
            $ref->invoke($model, 'posts');
            $ref->invoke($model, 'posts');
            $ref->invoke($model, 'posts');

            $occurrences = substr_count(file_get_contents($tmp), '[GLUEFUL-N+1]');
            $this->assertSame(1, $occurrences, 'Same pair should only warn once');
        } finally {
            ini_set('error_log', $prevLog);
            @unlink($tmp);
        }
    }

    public function testClearLazyLoadingWarningsAllowsRewarning(): void
    {
        Model::preventLazyLoading('warn');

        $tmp = tempnam(sys_get_temp_dir(), 'glueful-n1-');
        $prevLog = ini_set('error_log', $tmp);
        try {
            $model = new HydrationTaggingTestModel();
            $model->setLoadedFromCollection(true);

            $ref = new \ReflectionMethod($model, 'handleLazyLoadingViolation');
            $ref->setAccessible(true);
            $ref->invoke($model, 'posts');
            Model::clearLazyLoadingWarnings();
            $ref->invoke($model, 'posts');

            $occurrences = substr_count(file_get_contents($tmp), '[GLUEFUL-N+1]');
            $this->assertSame(2, $occurrences);
        } finally {
            ini_set('error_log', $prevLog);
            @unlink($tmp);
        }
    }

    public function testStrictModeThrowsException(): void
    {
        Model::preventLazyLoading('strict');

        $model = new HydrationTaggingTestModel();
        $model->setLoadedFromCollection(true);

        $ref = new \ReflectionMethod($model, 'handleLazyLoadingViolation');
        $ref->setAccessible(true);

        $this->expectException(\Glueful\Database\ORM\Exceptions\LazyLoadingViolationException::class);
        $ref->invoke($model, 'posts');
    }

    public function testStrictModeExceptionCarriesContext(): void
    {
        Model::preventLazyLoading('strict');

        $model = new HydrationTaggingTestModel();
        $model->setLoadedFromCollection(true);

        $ref = new \ReflectionMethod($model, 'handleLazyLoadingViolation');
        $ref->setAccessible(true);

        try {
            $ref->invoke($model, 'posts');
            $this->fail('Expected exception not thrown');
        } catch (\Glueful\Database\ORM\Exceptions\LazyLoadingViolationException $e) {
            $this->assertSame(HydrationTaggingTestModel::class, $e->modelClass);
            $this->assertSame('posts', $e->relation);
        }
    }

    public function testCustomCallbackReceivesModelAndRelation(): void
    {
        Model::preventLazyLoading('warn');

        $captured = null;
        Model::handleLazyLoadingViolationUsing(function ($model, $relation) use (&$captured) {
            $captured = [$model::class, $relation];
        });

        $model = new HydrationTaggingTestModel();
        $model->setLoadedFromCollection(true);

        $ref = new \ReflectionMethod($model, 'handleLazyLoadingViolation');
        $ref->setAccessible(true);
        $ref->invoke($model, 'posts');

        $this->assertSame([HydrationTaggingTestModel::class, 'posts'], $captured);
    }

    public function testCustomCallbackReplacesDefaultBehavior(): void
    {
        Model::preventLazyLoading('strict');

        $invoked = false;
        Model::handleLazyLoadingViolationUsing(function () use (&$invoked) {
            $invoked = true;
            // Note: NOT throwing — proves strict-mode default is replaced
        });

        $model = new HydrationTaggingTestModel();
        $model->setLoadedFromCollection(true);

        $ref = new \ReflectionMethod($model, 'handleLazyLoadingViolation');
        $ref->setAccessible(true);
        $ref->invoke($model, 'posts');  // Should NOT throw

        $this->assertTrue($invoked);
    }

    public function testNullCallbackClearsRegistration(): void
    {
        Model::preventLazyLoading('warn');

        $invoked = false;
        Model::handleLazyLoadingViolationUsing(function () use (&$invoked) {
            $invoked = true;
        });
        Model::handleLazyLoadingViolationUsing(null);

        // After clearing, default warn behavior resumes (we just check the callback didn't fire)
        $tmp = tempnam(sys_get_temp_dir(), 'glueful-n1-');
        $prevLog = ini_set('error_log', $tmp);
        try {
            $model = new HydrationTaggingTestModel();
            $model->setLoadedFromCollection(true);

            $ref = new \ReflectionMethod($model, 'handleLazyLoadingViolation');
            $ref->setAccessible(true);
            $ref->invoke($model, 'posts');

            $this->assertFalse($invoked);
            $this->assertStringContainsString('[GLUEFUL-N+1]', file_get_contents($tmp));
        } finally {
            ini_set('error_log', $prevLog);
            @unlink($tmp);
        }
    }

    public function testPerModelOptOutBeatsGlobalStrict(): void
    {
        Model::preventLazyLoading('strict');

        $model = new LegacyOptOutModel();
        $model->setLoadedFromCollection(true);

        $ref = new \ReflectionMethod($model, 'handleLazyLoadingViolation');
        $ref->setAccessible(true);
        $ref->invoke($model, 'posts');  // Should NOT throw

        $this->assertTrue(true, 'No exception thrown — opt-out worked');
    }

    public function testPreventsLazyLoadingNowReturnsFalseForOptedOutModel(): void
    {
        Model::preventLazyLoading('strict');

        $model = new LegacyOptOutModel();
        $model->setLoadedFromCollection(true);

        $ref = new \ReflectionMethod($model, 'preventsLazyLoadingNow');
        $ref->setAccessible(true);
        $this->assertFalse($ref->invoke($model));
    }
}

class LegacyOptOutModel extends \Glueful\Database\ORM\Model
{
    protected string $table = 'fake';
    public bool $exists = false;
    protected ?string $instanceLazyLoadingMode = 'off';
}
