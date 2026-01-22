<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks;

use Glueful\Database\ORM\Model;
use Glueful\Database\ORM\Relations\BelongsTo;
use Glueful\Helpers\Utils;

/**
 * Webhook Delivery Model
 *
 * Tracks individual webhook delivery attempts including
 * status, response, and retry scheduling.
 *
 * @property int $id
 * @property string $uuid
 * @property int $subscription_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property int|null $response_code
 * @property string|null $response_body
 * @property string|null $delivered_at
 * @property string|null $next_retry_at
 * @property string $created_at
 */
class WebhookDelivery extends Model
{
    protected string $table = 'webhook_deliveries';

    public const STATUS_PENDING = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RETRYING = 'retrying';

    /** @var array<string> */
    protected array $fillable = [
        'uuid',
        'subscription_id',
        'event',
        'payload',
        'status',
        'attempts',
        'response_code',
        'response_body',
        'delivered_at',
        'next_retry_at',
        'created_at',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'response_code' => 'integer',
        'subscription_id' => 'integer',
    ];

    /** @var array<string> */
    protected array $hidden = [
        'id',
    ];

    public bool $timestamps = false;

    /**
     * Boot the model
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (WebhookDelivery $model): void {
            if ($model->uuid === null || $model->uuid === '') {
                $model->uuid = 'wh_del_' . Utils::generateNanoID(16);
            }
            if ($model->status === null || $model->status === '') {
                $model->status = self::STATUS_PENDING;
            }
            if (!isset($model->attempts)) {
                $model->attempts = 0;
            }
            if ($model->created_at === null || $model->created_at === '') {
                $model->created_at = date('Y-m-d H:i:s');
            }
        });
    }

    /**
     * Get the subscription this delivery belongs to
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'subscription_id');
    }

    /**
     * Mark delivery as successful
     */
    public function markDelivered(int $statusCode, string $body): void
    {
        $this->update([
            'status' => self::STATUS_DELIVERED,
            'response_code' => $statusCode,
            'response_body' => substr($body, 0, 65535),
            'delivered_at' => date('Y-m-d H:i:s'),
            'next_retry_at' => null,
        ]);
    }

    /**
     * Mark delivery as failed (no more retries)
     */
    public function markFailed(int $statusCode, string $body): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'response_code' => $statusCode,
            'response_body' => substr($body, 0, 65535),
        ]);
    }

    /**
     * Schedule a retry
     */
    public function scheduleRetry(\DateTimeInterface $at): void
    {
        $this->update([
            'status' => self::STATUS_RETRYING,
            'next_retry_at' => $at->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Increment the attempts counter
     */
    public function incrementAttempts(): void
    {
        $this->update(['attempts' => $this->attempts + 1]);
    }

    /**
     * Check if delivery is pending
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if delivery was successful
     */
    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    /**
     * Check if delivery failed
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if delivery is awaiting retry
     */
    public function isRetrying(): bool
    {
        return $this->status === self::STATUS_RETRYING;
    }

    /**
     * Get duration until next retry (in seconds)
     *
     * @return int|null Seconds until retry, or null if not scheduled
     */
    public function getRetryDelay(): ?int
    {
        if ($this->next_retry_at === null) {
            return null;
        }

        $retryTime = strtotime($this->next_retry_at);
        if ($retryTime === false) {
            return null;
        }

        $delay = $retryTime - time();

        return max(0, $delay);
    }

    /**
     * Check if ready for retry
     */
    public function isReadyForRetry(): bool
    {
        if ($this->status !== self::STATUS_RETRYING) {
            return false;
        }

        $delay = $this->getRetryDelay();

        return $delay !== null && $delay <= 0;
    }

    /**
     * Reset for manual retry
     */
    public function resetForRetry(): void
    {
        $this->update([
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'response_code' => null,
            'response_body' => null,
            'delivered_at' => null,
            'next_retry_at' => null,
        ]);
    }
}
