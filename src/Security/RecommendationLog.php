<?php

declare(strict_types=1);

namespace Glueful\Security;

/**
 * Once-per-boot-cache logging for production RECOMMENDATIONS.
 *
 * PHP-FPM boots the framework on every request, so logging advisory recommendations at boot
 * put one line per hit in the error log for as long as the recommendation applied. A marker
 * under the app's cache directory records the last set logged; the same set stays silent
 * until the set changes or the cache is cleared (any `cache:clear` removes the marker). An
 * empty set removes the marker so a regression is logged again. WARNINGS are not routed here:
 * they keep their per-request logging.
 */
final class RecommendationLog
{
    private const MARKER = 'security_recommendations.marker';

    /** @var \Closure(string): void */
    private \Closure $sink;

    /**
     * @param string $cacheDir The app's `storage/cache` (cleared with the other boot caches).
     * @param (\Closure(string): void)|null $sink Line sink; defaults to error_log().
     */
    public function __construct(private readonly string $cacheDir, ?\Closure $sink = null)
    {
        $this->sink = $sink ?? static function (string $line): void {
            error_log($line);
        };
    }

    /**
     * @param list<string> $recommendations
     * @return bool Whether anything was logged this boot.
     */
    public function logOnce(array $recommendations): bool
    {
        $marker = $this->cacheDir . '/' . self::MARKER;

        if ($recommendations === []) {
            if (is_file($marker)) {
                @unlink($marker);
            }
            return false;
        }

        $signature = hash('sha256', implode("\n", $recommendations));
        if (is_file($marker) && hash_equals((string) @file_get_contents($marker), $signature)) {
            return false;
        }

        foreach ($recommendations as $rec) {
            ($this->sink)('[security] RECOMMENDATION: ' . $rec);
        }

        // Best-effort: an unwritable cache dir simply means logging every boot, as before.
        if (is_dir($this->cacheDir) || @mkdir($this->cacheDir, 0755, true)) {
            @file_put_contents($marker, $signature, LOCK_EX);
        }

        return true;
    }
}
