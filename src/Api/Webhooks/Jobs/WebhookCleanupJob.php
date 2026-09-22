<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks\Jobs;

use Glueful\Api\Webhooks\Webhook;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Queue\Job;
use Glueful\Queue\Jobs\ResolvesJobLogger;

/**
 * Scheduled removal of webhook delivery records past `api.webhooks.cleanup` retention.
 *
 * Schedule it from config/schedule.php:
 *   ['name' => 'webhook_cleanup', 'schedule' => '30 3 * * *',
 *    'handler_class' => \Glueful\Api\Webhooks\Jobs\WebhookCleanupJob::class]
 */
class WebhookCleanupJob extends Job
{
    use ResolvesJobLogger;

    public function __construct(array $data = [], ?ApplicationContext $context = null)
    {
        parent::__construct($data, $context);
        $this->queue = 'maintenance';
    }

    public function handle(): void
    {
        if ($this->context === null) {
            throw new \RuntimeException('WebhookCleanupJob needs the application context');
        }

        Webhook::setContext($this->context);
        $removed = Webhook::cleanup();

        $this->jobLogger()->info('Webhook deliveries cleaned up', $removed);
    }
}
