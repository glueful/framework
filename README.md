# framework
Glueful API Framework - High-performance PHP API framework

## File Cache Enhancements

- Sharding: To avoid large flat directories for file cache, you can shard cache files by enabling hashed subdirectories.

```php
use Glueful\Cache\Drivers\FileCacheDriver;
use Glueful\Services\FileManager;
use Glueful\Services\FileFinder;

$cache = new FileCacheDriver(__DIR__ . '/storage/cache', new FileManager(), new FileFinder());
$cache->setShardDirectories(true); // creates paths like cache/ab/<hash>.cache
```

- Stats Cache: For large caches, enable a lightweight in-process stats cache to avoid full directory scans on every `getStats()`.

```php
$cache->setStatsCacheEnabled(true); // lazily initializes and keeps counters updated on set/del
```

- Remember + null values: `remember()` now correctly handles cached nulls. If a key exists with a null value, `remember()` returns null instead of recomputing.
