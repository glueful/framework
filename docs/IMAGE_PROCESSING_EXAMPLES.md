# Image Processing Examples

This document provides comprehensive examples of using the Glueful Framework's image processing capabilities powered by Intervention Image v3.

## Basic Operations

### Simple Resize
```php
// Using helper function (recommended)
image('/path/to/photo.jpg')
    ->resize(800, 600)
    ->save('/path/to/resized.jpg');

// Using service injection
$processor = app(ImageProcessorInterface::class);
$result = $processor::make('/path/to/photo.jpg')
    ->resize(800, 600)
    ->save('/path/to/resized.jpg');
```

### Maintain Aspect Ratio
```php
// Resize maintaining aspect ratio
image('/path/to/photo.jpg')
    ->width(800)  // Height calculated automatically
    ->save('/path/to/resized.jpg');

// Or specify height
image('/path/to/photo.jpg')
    ->height(600)  // Width calculated automatically
    ->save('/path/to/resized.jpg');
```

### Quality Control
```php
// Set JPEG quality (1-100)
image('/path/to/photo.jpg')
    ->resize(800, 600)
    ->quality(85)
    ->save('/path/to/output.jpg');

// Format conversion with quality
image('/path/to/photo.png')
    ->format('webp')
    ->quality(80)
    ->save('/path/to/output.webp');
```

## Advanced Operations

### Cropping and Fitting
```php
// Crop to exact dimensions from center
image('/path/to/photo.jpg')
    ->crop(400, 300, 100, 50)  // width, height, x, y
    ->save('/path/to/cropped.jpg');

// Fit image within bounds (letterbox/pillarbox)
image('/path/to/photo.jpg')
    ->fit(800, 600)
    ->save('/path/to/fitted.jpg');

// Cover area (may crop edges)
image('/path/to/photo.jpg')
    ->cover(800, 600)
    ->save('/path/to/covered.jpg');
```

### Watermarking
```php
// Add watermark to bottom-right
image('/path/to/photo.jpg')
    ->watermark('/path/to/logo.png', 'bottom-right', 50)  // 50% opacity
    ->save('/path/to/watermarked.jpg');

// Custom watermark positioning
image('/path/to/photo.jpg')
    ->watermark('/path/to/logo.png', 'top-left', 75, 10, 10)  // opacity, x, y offsets
    ->save('/path/to/watermarked.jpg');
```

### Filters and Effects
```php
// Apply grayscale filter
image('/path/to/photo.jpg')
    ->grayscale()
    ->save('/path/to/grayscale.jpg');

// Blur effect
image('/path/to/photo.jpg')
    ->blur(15)  // blur amount
    ->save('/path/to/blurred.jpg');

// Brightness and contrast
image('/path/to/photo.jpg')
    ->brightness(20)   // -100 to 100
    ->contrast(15)     // -100 to 100
    ->save('/path/to/adjusted.jpg');
```

## Caching Examples

### Basic Caching
```php
// Cache processed image for 24 hours
$imageData = image('/path/to/photo.jpg')
    ->resize(800, 600)
    ->quality(85)
    ->cached('photo-800x600', 86400)
    ->getImageData();
```

### Cache Key Generation
```php
// Automatic cache key based on operations
$processor = image('/path/to/photo.jpg')
    ->resize(800, 600)
    ->quality(85)
    ->cached();  // Auto-generates cache key

// Custom cache key with TTL
$processor = image('/path/to/photo.jpg')
    ->resize(800, 600)
    ->cached('custom-thumbnail-' . $userId, 3600);
```

### Cache Invalidation
```php
// Clear specific cached image
$processor = image('/path/to/photo.jpg');
$processor->clearCache('photo-800x600');

// Clear all cached versions
$processor->clearCache();
```

## Remote Image Processing

### Basic Remote Processing
```php
// Process remote image with security checks
try {
    $result = image('https://example.com/photo.jpg')
        ->resize(800, 600)
        ->quality(85)
        ->cached('remote-photo-800x600')
        ->save('/path/to/local.jpg');
} catch (SecurityException $e) {
    // Handle security violations (domain not allowed, etc.)
    echo "Security error: " . $e->getMessage();
} catch (ProcessingException $e) {
    // Handle processing errors
    echo "Processing error: " . $e->getMessage();
}
```

### Batch Processing
```php
// Process multiple images efficiently
$urls = [
    'https://example.com/photo1.jpg',
    'https://example.com/photo2.jpg',
    'https://example.com/photo3.jpg'
];

foreach ($urls as $index => $url) {
    try {
        image($url)
            ->resize(400, 300)
            ->cached("batch-photo-{$index}")
            ->save("/path/to/batch_{$index}.jpg");
    } catch (Exception $e) {
        // Log error and continue
        error_log("Failed to process {$url}: " . $e->getMessage());
    }
}
```

## Working with Uploads

### Process File Uploads
```php
// In controller handling file upload
public function uploadImage(Request $request): Response
{
    $uploadedFile = $request->getUploadedFiles()['image'] ?? null;
    
    if (!$uploadedFile) {
        return Response::error('No image uploaded');
    }
    
    try {
        // Process uploaded file
        $processedData = image($uploadedFile)
            ->resize(1200, 800)
            ->quality(85)
            ->format('webp')
            ->cached('upload-' . uniqid())
            ->getImageData();
            
        // Save to storage
        $filename = 'processed_' . time() . '.webp';
        file_put_contents(storage_path('images/' . $filename), $processedData);
        
        return Response::success(['filename' => $filename]);
        
    } catch (Exception $e) {
        return Response::error('Image processing failed: ' . $e->getMessage());
    }
}
```

### Generate Multiple Sizes
```php
// Create thumbnail variants from upload
$uploadedFile = $request->getUploadedFiles()['image'];
$processor = image($uploadedFile);

$sizes = [
    'thumbnail' => [150, 150],
    'medium' => [400, 300],
    'large' => [800, 600],
    'original' => [1200, 900]
];

$results = [];
foreach ($sizes as $size => $dimensions) {
    $processed = $processor->copy()
        ->fit($dimensions[0], $dimensions[1])
        ->quality(85)
        ->cached("upload-{$size}-" . uniqid())
        ->getImageData();
        
    $filename = "{$size}_" . time() . '.jpg';
    file_put_contents(storage_path("images/{$filename}"), $processed);
    $results[$size] = $filename;
}
```

## Performance Optimization

### Memory Efficient Processing
```php
// Process large images efficiently
$processor = image('/path/to/large-image.jpg');

// Check dimensions before processing
if ($processor->width() > 4000 || $processor->height() > 4000) {
    // Resize very large images first
    $processor->resize(2000, 1500);
}

// Continue with normal processing
$result = $processor
    ->resize(800, 600)
    ->quality(85)
    ->save('/path/to/output.jpg');
```

### Streaming for Large Files
```php
// Stream large processed images
public function serveProcessedImage(string $path): void
{
    $processor = image($path)
        ->resize(1200, 800)
        ->cached();
        
    // Stream directly to output
    $processor->stream([
        'Content-Disposition' => 'inline; filename="processed.jpg"'
    ]);
}
```

## Error Handling

### Comprehensive Error Handling
```php
try {
    $result = image('/path/to/photo.jpg')
        ->resize(800, 600)
        ->quality(85)
        ->save('/path/to/output.jpg');
        
} catch (SecurityException $e) {
    // Security violations (file too large, suspicious content, etc.)
    error_log("Security error: " . $e->getMessage());
    
} catch (ProcessingException $e) {
    // Processing errors (invalid image, unsupported format, etc.)
    error_log("Processing error: " . $e->getMessage());
    
} catch (CacheException $e) {
    // Caching errors (cache store unavailable, etc.)
    error_log("Cache error: " . $e->getMessage());
    
} catch (Exception $e) {
    // Generic errors
    error_log("Unexpected error: " . $e->getMessage());
}
```

## Configuration Examples

### Environment Variables
```env
# Production settings
IMAGE_DRIVER=imagick
IMAGE_MAX_WIDTH=2048
IMAGE_MAX_HEIGHT=2048
IMAGE_MAX_FILESIZE=10M
IMAGE_JPEG_QUALITY=85
IMAGE_WEBP_QUALITY=80
IMAGE_CACHE_ENABLED=true
IMAGE_CACHE_TTL=86400
IMAGE_VALIDATE_MIME=true
IMAGE_CHECK_INTEGRITY=true
IMAGE_DISABLE_EXTERNAL_URLS=false
IMAGE_ALLOWED_DOMAINS=example.com,cdn.example.com
```

### Custom Configuration in Services
```php
// Custom image processor with specific settings
$processor = new ImageProcessor(
    $imageManager,
    $cache,
    $security,
    $logger,
    [
        'optimization' => [
            'jpeg_quality' => 90,
            'webp_quality' => 85,
        ],
        'security' => [
            'allowed_domains' => ['trusted-cdn.com'],
            'validate_mime' => true,
        ],
        'cache' => [
            'enabled' => true,
            'ttl' => 3600,
            'prefix' => 'custom-images-',
        ]
    ]
);
```

## Integration with Controllers

### RESTful Image API
```php
class ImageController extends BaseController
{
    public function resize(Request $request): Response
    {
        $this->requirePermission('images.process');
        
        $url = $request->get('url');
        $width = (int) $request->get('width', 800);
        $height = (int) $request->get('height', 600);
        $quality = (int) $request->get('quality', 85);
        
        try {
            $imageData = image($url)
                ->resize($width, $height)
                ->quality($quality)
                ->cached("resize-{$width}x{$height}-q{$quality}")
                ->getImageData();
                
            return Response::success([
                'processed' => true,
                'size' => strlen($imageData),
                'data' => base64_encode($imageData)
            ]);
            
        } catch (Exception $e) {
            return Response::error('Processing failed: ' . $e->getMessage());
        }
    }
}
```

This completes the comprehensive examples for the Glueful Framework's image processing capabilities.