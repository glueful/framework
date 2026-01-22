<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks;

use Glueful\Database\ORM\Model;
use Glueful\Database\ORM\Relations\HasMany;
use Glueful\Helpers\Utils;

/**
 * Webhook Subscription Model
 *
 * Represents a webhook subscription that listens to specific events
 * and receives HTTP notifications when those events occur.
 *
 * @property int $id
 * @property string $uuid
 * @property string $url
 * @property array<string> $events
 * @property string $secret
 * @property bool $is_active
 * @property array<string, mixed>|null $metadata
 * @property string $created_at
 * @property string $updated_at
 */
class WebhookSubscription extends Model
{
    protected string $table = 'webhook_subscriptions';

    /** @var array<string> */
    protected array $fillable = [
        'uuid',
        'url',
        'events',
        'secret',
        'is_active',
        'metadata',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'events' => 'array',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    /** @var array<string> */
    protected array $hidden = [
        'secret',
        'id',
    ];

    /**
     * Boot the model
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (WebhookSubscription $model): void {
            if ($model->uuid === null || $model->uuid === '') {
                $model->uuid = 'wh_sub_' . Utils::generateNanoID(16);
            }
            if ($model->secret === null || $model->secret === '') {
                $model->secret = self::generateSecret();
            }
        });
    }

    /**
     * Generate a new subscription secret
     */
    public static function generateSecret(): string
    {
        return 'whsec_' . bin2hex(random_bytes(32));
    }

    /**
     * Check if subscription listens to an event
     *
     * Supports exact matches, wildcards (*), and prefix wildcards (user.*)
     *
     * @param string $event Event name to check (e.g., 'user.created')
     */
    public function listensTo(string $event): bool
    {
        foreach ($this->events as $pattern) {
            // Exact match
            if ($pattern === $event) {
                return true;
            }

            // All events wildcard
            if ($pattern === '*') {
                return true;
            }

            // Prefix wildcard match (e.g., 'user.*' matches 'user.created')
            if (str_ends_with($pattern, '.*')) {
                $prefix = substr($pattern, 0, -2);
                if (str_starts_with($event, $prefix . '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get all deliveries for this subscription
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'subscription_id');
    }

    /**
     * Get recent delivery statistics
     *
     * @param int $days Number of days to look back
     * @return array{total: int, delivered: int, failed: int, pending: int}
     */
    public function stats(int $days = 30): array
    {
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $deliveries = WebhookDelivery::query()
            ->where('subscription_id', $this->id)
            ->where('created_at', '>=', $since)
            ->get();

        $total = $deliveries->count();
        $delivered = 0;
        $failed = 0;
        $pending = 0;

        /** @var WebhookDelivery $delivery */
        foreach ($deliveries as $delivery) {
            $status = $delivery->status;
            match ($status) {
                WebhookDelivery::STATUS_DELIVERED => $delivered++,
                WebhookDelivery::STATUS_FAILED => $failed++,
                WebhookDelivery::STATUS_PENDING,
                WebhookDelivery::STATUS_RETRYING => $pending++,
                default => null,
            };
        }

        return [
            'total' => $total,
            'delivered' => $delivered,
            'failed' => $failed,
            'pending' => $pending,
        ];
    }

    /**
     * Get success rate for this subscription
     *
     * @param int $days Number of days to look back
     * @return float Success rate as percentage (0-100)
     */
    public function successRate(int $days = 30): float
    {
        $stats = $this->stats($days);

        if ($stats['total'] === 0) {
            return 100.0;
        }

        return round(($stats['delivered'] / $stats['total']) * 100, 2);
    }

    /**
     * Activate the subscription
     */
    public function activate(): bool
    {
        return $this->update(['is_active' => true]);
    }

    /**
     * Deactivate the subscription
     */
    public function deactivate(): bool
    {
        return $this->update(['is_active' => false]);
    }

    /**
     * Rotate the webhook secret
     *
     * @return string The new secret
     */
    public function rotateSecret(): string
    {
        $newSecret = self::generateSecret();
        $this->update(['secret' => $newSecret]);

        return $newSecret;
    }
}
