<?php

declare(strict_types=1);

namespace Glueful\Uploader\Jobs;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Queue\Job;
use Glueful\Queue\Jobs\ResolvesJobLogger;
use Glueful\Queue\QueueManager;
use Glueful\Uploader\BlobVariantRenderer;
use Glueful\Uploader\BlobVariantStore;
use Psr\Log\LoggerInterface;

use function config;

/**
 * Makes an uploaded image's resized copies ahead of the first request for them, so no visitor waits
 * for one to be rendered: one per `uploads.image_processing.pregenerate.widths` and `.formats`
 * (a null format is the image's own), for images whose type is in `.mime_types`. A copy already
 * stored is skipped, so running it again — after an image is replaced in place, say — only makes
 * what is missing. Off unless `pregenerate.enabled`; the upload route queues it (dispatchFor()).
 */
class GenerateBlobVariantsJob extends Job
{
    use ResolvesJobLogger;

    /**
     * Queue the copies of one image, when pre-generation is on. Never throws: a queue that is down
     * leaves the copies to be rendered on request, as without pre-generation. True when queued.
     */
    public static function dispatchFor(ApplicationContext $context, string $blobUuid): bool
    {
        if (!(bool) config($context, 'uploads.image_processing.pregenerate.enabled', false) || $blobUuid === '') {
            return false;
        }
        if ((array) config($context, 'uploads.image_processing.pregenerate.widths', []) === []) {
            return false;
        }
        $container = $context->getContainer();
        try {
            $queue = $container->get(QueueManager::class);
            if (!$queue instanceof QueueManager) {
                return false;
            }
            $queue->push(
                self::class,
                ['blob_uuid' => $blobUuid],
                (string) config($context, 'uploads.image_processing.pregenerate.queue', 'default'),
            );

            return true;
        } catch (\Throwable $e) {
            $logger = $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null;
            if ($logger instanceof LoggerInterface) {
                $logger->warning('Image copies not queued; they will be made on request', [
                    'blob_uuid' => $blobUuid,
                    'error' => $e->getMessage(),
                ]);
            }

            return false;
        }
    }

    public function handle(): void
    {
        if ($this->context === null) {
            throw new \RuntimeException('GenerateBlobVariantsJob needs the application context');
        }
        $context = $this->context;
        $uuid = (string) ($this->getData()['blob_uuid'] ?? '');
        $blob = $uuid === '' ? null : Connection::fromContext($context)->table('blobs')
            ->where('uuid', '=', $uuid)
            ->where('status', '=', 'active')
            ->first();
        if (!is_array($blob)) {
            return;
        }
        $mime = (string) ($blob['mime_type'] ?? '');
        $types = (array) config(
            $context,
            'uploads.image_processing.pregenerate.mime_types',
            ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        );
        if (BlobVariantRenderer::formatFromMime($mime) === null || !in_array($mime, $types, true)) {
            return;
        }
        $renderer = BlobVariantRenderer::fromContext($context);
        if ($renderer === null) {
            return;
        }
        $store = BlobVariantStore::fromContext($context);
        $made = 0;
        foreach ($this->settings($context) as $resize) {
            $expected = BlobVariantRenderer::expectedMime($mime, $resize);
            if ($store->has($blob, $resize, $expected)) {
                continue;
            }
            try {
                ['data' => $data, 'mime' => $rendered] = $renderer->render($blob, $resize);
            } catch (\Throwable $e) {
                $this->jobLogger()->warning('Image copy not made; it will be rendered on request', [
                    'blob_uuid' => $uuid,
                    'width' => $resize['width'],
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
            if ($rendered === $expected && $store->put($blob, $resize, $rendered, $data)) {
                $made++;
            }
        }
        if ($made > 0) {
            $this->jobLogger()->info('Image copies made', ['blob_uuid' => $uuid, 'copies' => $made]);
        }
    }

    /**
     * @return list<array{width: int|null, height: int|null, quality: int|null, format: string|null, fit: string|null}>
     */
    private function settings(ApplicationContext $context): array
    {
        $formats = (array) config($context, 'uploads.image_processing.pregenerate.formats', [null]);
        $settings = [];
        foreach ((array) config($context, 'uploads.image_processing.pregenerate.widths', []) as $width) {
            if (!is_numeric($width) || (int) $width <= 0) {
                continue;
            }
            foreach ($formats === [] ? [null] : $formats as $format) {
                $settings[] = BlobVariantStore::normalize([
                    'width' => (int) $width,
                    'format' => is_string($format) && $format !== '' ? $format : null,
                ]);
            }
        }

        return $settings;
    }
}
