# Image Processor Integration with Glueful Framework

## Framework Pattern Integration

### 1. **Dependency Injection Container Integration**

```php
// Service Provider Registration
class ImageServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        // Register ImageManager with GD Driver
        $this->container->singleton(ImageManager::class, function() {
            $driver = config('image.driver') === 'imagick' 
                ? new ImagickDriver() 
                : new GdDriver();
            return new ImageManager($driver);
        });
        
        // Register our ImageProcessor implementation
        $this->container->bind(ImageProcessorInterface::class, 
            InterventionImageProcessor::class);
        
        // Register security validator
        $this->container->bind(ImageSecurityValidator::class, function() {
            return new ImageSecurityValidator(config('image.security', []));
        });
    }
}
```

### 2. **Cache Integration**

```php
// Uses existing Glueful cache system
class InterventionImageProcessor implements ImageProcessorInterface
{
    private CacheInterface $cache;
    
    public function cached(?string $key = null, int $ttl = 3600): self
    {
        $cacheKey = $key ?? $this->generateCacheKey();
        
        // Use existing framework cache with tags
        $this->cache->set(
            config('image.cache.prefix') . $cacheKey,
            $this->getImageData(),
            $ttl,
            config('image.cache.tags', ['images'])
        );
        
        return $this;
    }
}
```

### 3. **Exception Handling Integration**

```php
// Uses existing Glueful exception system
use Glueful\Exceptions\BusinessLogicException;
use Glueful\Exceptions\DatabaseException;

public function processImage(): void
{
    try {
        // Process image
    } catch (\Exception $e) {
        throw BusinessLogicException::operationNotAllowed(
            'image_processing',
            'Failed to process image: ' . $e->getMessage()
        );
    }
}
```

### 4. **Configuration Integration**

```php
// Integrates with existing config() helper
$driver = config('image.driver', 'gd');
$maxWidth = config('image.limits.max_width', 2048);
$cacheEnabled = config('image.cache.enabled', true);

// Environment variable support
IMAGE_DRIVER=gd
IMAGE_MAX_WIDTH=2048
IMAGE_CACHE_ENABLED=true
```

### 5. **Logging Integration**

```php
use Psr\Log\LoggerInterface;

class InterventionImageProcessor
{
    private LoggerInterface $logger;
    
    private function logProcessingTime(float $startTime): void
    {
        if (config('image.monitoring.log_processing_time')) {
            $duration = microtime(true) - $startTime;
            $this->logger->info('Image processed', [
                'duration_ms' => round($duration * 1000, 2),
                'type' => 'image_processing'
            ]);
        }
    }
}
```

### 6. **HTTP Response Integration**

```php
use Glueful\Http\Response;

public function toResponse(array $headers = []): Response
{
    $imageData = $this->getImageData();
    $mimeType = $this->getMimeType();
    
    return Response::create($imageData, 200, array_merge([
        'Content-Type' => $mimeType,
        'Content-Length' => strlen($imageData),
        'Cache-Control' => 'public, max-age=3600',
    ], $headers));
}
```

### 7. **Permission System Integration** 

```php
// FilesController integration
class FilesController extends BaseController
{
    public function processImage(): Response
    {
        // Use existing permission system
        $this->requirePermission('files.image.process');
        
        // Use existing rate limiting
        $this->rateLimitResource('images', 'process', 10, 60);
        
        // Process with image processor
        $result = $this->imageProcessor
            ->make($imageUrl)
            ->resize($width, $height)
            ->quality($quality)
            ->cached()
            ->toResponse();
            
        return $result;
    }
}
```

### 8. **Helper Function Integration**

```php
// src/helpers.php addition
if (!function_exists('image')) {
    function image(string $source): ImageProcessorInterface {
        return app(ImageProcessorInterface::class)::make($source);
    }
}

// Usage
$processed = image('/path/to/image.jpg')
    ->resize(800, 600)
    ->quality(85)
    ->cached()
    ->save('/path/to/output.jpg');
```

### 9. **Middleware Integration** (Future)

```php
// Rate limiting middleware for image processing
class ImageProcessingRateLimitMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Apply image-specific rate limits
        $this->applyImageProcessingLimits($request);
        
        return $next($request);
    }
}
```

### 10. **Event System Integration** (Future)

```php
// Events for monitoring
Event::dispatch(new ImageProcessed($imageUrl, $operations, $duration));
Event::dispatch(new ImageCached($cacheKey, $size));
Event::dispatch(new ImageProcessingFailed($imageUrl, $error));
```

## Key Integration Points

1. **Container Bindings**: Register in DI container like other services
2. **Configuration**: Uses config() helper and .env variables  
3. **Caching**: Integrates with existing cache system and tags
4. **Exceptions**: Uses framework exception hierarchy
5. **Logging**: Uses PSR-3 logger from container
6. **HTTP**: Returns proper Response objects
7. **Permissions**: Works with existing permission system
8. **Rate Limiting**: Uses existing rate limiting infrastructure
9. **Helper Functions**: Follows framework helper pattern
10. **Service Providers**: Registered like other framework services

This ensures the image processor feels native to the Glueful framework while maintaining clean separation of concerns.