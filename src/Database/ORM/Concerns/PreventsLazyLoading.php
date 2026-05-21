<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Concerns;

use Glueful\Database\ORM\Exceptions\LazyLoadingViolationException;

/**
 * ORM-aware N+1 detection. Models tagged as loaded-from-collection by
 * Builder::hydrate() route relation-access attempts through this trait
 * when global mode is 'warn' or 'strict'. See:
 *   docs/ORM/N_PLUS_ONE_DETECTION.md
 *   docs/superpowers/specs/2026-05-20-n-plus-one-detection-design.md
 */
trait PreventsLazyLoading
{
    protected static string $lazyLoadingMode = 'off';

    protected static ?\Closure $violationCallback = null;

    /** @var array<string, true> */
    protected static array $warnedPairs = [];

    protected ?string $instanceLazyLoadingMode = null;

    protected bool $loadedFromCollection = false;

    public static function preventLazyLoading(string $mode = 'strict'): void
    {
        self::$lazyLoadingMode = self::resolveAutoMode($mode);
    }

    public static function lazyLoadingEnabled(): bool
    {
        return self::$lazyLoadingMode !== 'off';
    }

    public static function resolvedGlobalMode(): string
    {
        return self::$lazyLoadingMode;
    }

    public static function resetLazyLoadingState(): void
    {
        self::$lazyLoadingMode = 'off';
        self::$violationCallback = null;
        self::$warnedPairs = [];
    }

    public static function handleLazyLoadingViolationUsing(?\Closure $callback): void
    {
        self::$violationCallback = $callback;
    }

    private static function resolveAutoMode(string $mode): string
    {
        if ($mode !== 'auto') {
            return $mode;
        }

        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '';

        return $env === 'development' ? 'warn' : 'off';
    }

    public function setLoadedFromCollection(bool $value): void
    {
        $this->loadedFromCollection = $value;
    }

    public function wasLoadedFromCollection(): bool
    {
        return $this->loadedFromCollection;
    }

    public static function clearLazyLoadingWarnings(): void
    {
        self::$warnedPairs = [];
    }

    protected function preventsLazyLoadingNow(): bool
    {
        if (!$this->loadedFromCollection) {
            return false;
        }

        $mode = $this->instanceLazyLoadingMode ?? self::$lazyLoadingMode;

        return $mode !== 'off';
    }

    protected function handleLazyLoadingViolation(string $relation): void
    {
        if (self::$violationCallback !== null) {
            (self::$violationCallback)($this, $relation);
            return;
        }

        $mode = $this->instanceLazyLoadingMode ?? self::$lazyLoadingMode;

        if ($mode === 'strict') {
            throw new LazyLoadingViolationException(static::class, $relation);
        }

        if ($mode === 'warn') {
            $key = static::class . '::' . $relation;
            if (isset(self::$warnedPairs[$key])) {
                return;
            }
            self::$warnedPairs[$key] = true;

            error_log(sprintf(
                "[GLUEFUL-N+1] Lazy-load detected on collection-loaded model: %s::%s. "
                . "Add ->with('%s') to the query.",
                static::class,
                $relation,
                $relation,
            ));
        }
    }
}
