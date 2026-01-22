# Webhooks System Implementation Plan

> A comprehensive plan for implementing a robust webhook system with subscription management, delivery tracking, retry logic, and signature verification in Glueful Framework.

## ✅ Implementation Status: COMPLETE

**Implemented in:** v1.18.0
**Completed:** January 2026

### Summary of Implementation

The Webhooks System has been fully implemented with all planned features:

- **46 unit tests** covering signature generation, payload building, subscription matching, and delivery tracking
- **Auto-migration** for database tables (follows `DatabaseLogHandler` pattern)
- **HMAC-SHA256 signatures** in Stripe-style format (`t=timestamp,v1=signature`)
- **Exponential backoff retry** (1m, 5m, 30m, 2h, 12h)
- **REST API** for subscription management
- **CLI commands** for administration (`webhook:list`, `webhook:test`, `webhook:retry`)
- **Event integration** via `DispatchesWebhooks` trait and `#[Webhookable]` attribute

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Goals and Non-Goals](#goals-and-non-goals)
3. [Current State Analysis](#current-state-analysis)
4. [Architecture Design](#architecture-design)
5. [Subscription Management](#subscription-management)
6. [Webhook Delivery](#webhook-delivery)
7. [Security & Signatures](#security--signatures)
8. [Implementation Phases](#implementation-phases)
9. [Testing Strategy](#testing-strategy)
10. [API Reference](#api-reference)

---

## Executive Summary

This document outlines the implementation of a comprehensive webhook system for Glueful Framework. The system enables event-driven integrations by allowing external services to subscribe to application events and receive real-time HTTP notifications.

### Key Features

- **Event-Based Subscriptions**: Subscribe to specific events or event patterns (e.g., `user.*`)
- **Reliable Delivery**: Queue-based delivery with exponential backoff retry
- **HMAC Signatures**: Cryptographic signatures for payload verification
- **Delivery Tracking**: Complete audit trail with response logging
- **Testing Tools**: CLI commands for testing webhook endpoints
- **Self-Service API**: REST endpoints for managing subscriptions

---

## Goals and Non-Goals

### Goals

- ✅ Event-based webhook subscriptions (subscribe to `user.created`, `order.*`, etc.)
- ✅ Reliable delivery with automatic retry and exponential backoff
- ✅ HMAC-SHA256 signature for payload verification
- ✅ Delivery tracking with attempt history
- ✅ Queue integration for async delivery
- ✅ Rate limiting per subscription
- ✅ CLI tools for testing and management
- ✅ REST API for subscription management

### Non-Goals

- ❌ Webhook receiving (incoming webhooks from external services)
- ❌ Real-time WebSocket streaming (different system)
- ❌ GraphQL subscriptions
- ❌ Message broker integration (Kafka, RabbitMQ)

---

## Current State Analysis

### Existing Infrastructure

| Component | Status | Description |
|-----------|--------|-------------|
| Event System | ✅ Available | `Glueful\Events\Event` for dispatching events |
| Queue System | ✅ Available | Redis/Database queue with retry support |
| HTTP Client | ✅ Available | Symfony HttpClient (`Glueful\Http\Client`) |
| Encryption | ✅ Available | For generating secure secrets |

### What Was Built ✅

| Component | Description | Status |
|-----------|-------------|--------|
| Webhook Subscriptions | `WebhookSubscription` ORM model with wildcard matching | ✅ Complete |
| Delivery System | `DeliverWebhookJob` with exponential backoff retry | ✅ Complete |
| Signature Generation | `WebhookSignature` HMAC-SHA256 (Stripe-style) | ✅ Complete |
| Event Bridge | `WebhookEventListener` + `DispatchesWebhooks` trait | ✅ Complete |
| Management API | `WebhookController` REST endpoints | ✅ Complete |
| CLI Tools | `webhook:list`, `webhook:test`, `webhook:retry` | ✅ Complete |

---

## Architecture Design

### Component Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                    Application Event                             │
│              Event::dispatch(new UserCreated($user))            │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                   WebhookEventListener                           │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  1. Check if event is webhookable                        │   │
│  │  2. Find matching subscriptions                          │   │
│  │  3. Queue delivery jobs                                  │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Queue System                                │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │               DeliverWebhookJob                          │   │
│  │  • Build payload                                         │   │
│  │  • Generate signature                                    │   │
│  │  • Send HTTP request                                     │   │
│  │  • Log delivery result                                   │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                  External Service                                │
│  POST https://example.com/webhooks                              │
│  Headers: X-Webhook-Signature, X-Webhook-Event, X-Webhook-ID    │
│  Body: {"event": "user.created", "data": {...}}                 │
└─────────────────────────────────────────────────────────────────┘
```

### Directory Structure (Implemented)

```
src/Api/Webhooks/
├── Contracts/
│   ├── WebhookDispatcherInterface.php  # Dispatcher contract
│   └── WebhookPayloadInterface.php     # Payload builder contract
├── Concerns/
│   └── DispatchesWebhooks.php          # Trait for webhookable events
├── Attributes/
│   └── Webhookable.php                 # PHP 8 attribute for events
├── Events/
│   └── WebhookDispatchedEvent.php      # When webhooks are queued
├── Jobs/
│   └── DeliverWebhookJob.php           # Queue job for delivery
├── Listeners/
│   └── WebhookEventListener.php        # Bridge app events to webhooks
├── Http/Controllers/
│   └── WebhookController.php           # REST API controller
├── Webhook.php                         # Static facade
├── WebhookSubscription.php             # ORM model
├── WebhookDelivery.php                 # ORM model
├── WebhookDispatcher.php               # Core dispatcher + auto-migration
├── WebhookPayload.php                  # Payload builder
└── WebhookSignature.php                # HMAC signature generator

src/Console/Commands/Webhook/
├── WebhookListCommand.php              # List subscriptions
├── WebhookTestCommand.php              # Test webhook endpoint
└── WebhookRetryCommand.php             # Retry failed deliveries

tests/Unit/Api/Webhooks/
├── WebhookSignatureTest.php            # 16 tests
├── WebhookPayloadTest.php              # 8 tests
├── WebhookSubscriptionTest.php         # 11 tests
└── WebhookDeliveryTest.php             # 11 tests
```

### Auto-Migration Pattern

Following the pattern established by `DatabaseLogHandler`, webhook tables are **automatically created at runtime** when the feature is first used. No manual migrations or install commands required.

#### How It Works

```php
// WebhookDispatcher automatically ensures tables exist
$dispatcher = new WebhookDispatcher();
$dispatcher->dispatch('user.created', $data);
// ↳ Tables are created automatically if they don't exist
```

#### Implementation

```php
<?php

namespace Glueful\Api\Webhooks;

use Glueful\Database\Connection;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Central webhook dispatcher with auto-migration
 */
class WebhookDispatcher
{
    private SchemaBuilderInterface $schema;
    private Connection $db;
    private bool $tablesEnsured = false;

    public function __construct()
    {
        $connection = new Connection();
        $this->schema = $connection->getSchemaBuilder();
        $this->db = $connection;
    }

    /**
     * Dispatch a webhook event
     */
    public function dispatch(string $event, array $data, array $options = []): array
    {
        // Ensure tables exist on first use
        $this->ensureTables();

        // ... dispatch logic
    }

    /**
     * Ensure webhook tables exist
     *
     * Following the pattern from DatabaseLogHandler, tables are created
     * automatically at runtime if they don't exist.
     */
    private function ensureTables(): void
    {
        if ($this->tablesEnsured) {
            return;
        }

        $this->ensureSubscriptionsTable();
        $this->ensureDeliveriesTable();

        $this->tablesEnsured = true;
    }

    private function ensureSubscriptionsTable(): void
    {
        if ($this->schema->hasTable('webhook_subscriptions')) {
            return;
        }

        $table = $this->schema->table('webhook_subscriptions');

        $table->bigInteger('id')->unsigned()->primary()->autoIncrement();
        $table->string('uuid', 36)->unique();
        $table->string('url', 2048);
        $table->json('events');
        $table->string('secret', 255);
        $table->boolean('is_active')->default(true);
        $table->json('metadata')->nullable();
        $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
        $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

        $table->index('is_active');

        $table->create();
        $this->schema->execute();
    }

    private function ensureDeliveriesTable(): void
    {
        if ($this->schema->hasTable('webhook_deliveries')) {
            return;
        }

        $table = $this->schema->table('webhook_deliveries');

        $table->bigInteger('id')->unsigned()->primary()->autoIncrement();
        $table->string('uuid', 36)->unique();
        $table->bigInteger('subscription_id')->unsigned();
        $table->string('event', 255);
        $table->json('payload');
        $table->string('status', 20)->default('pending'); // pending, delivered, failed, retrying
        $table->integer('attempts')->unsigned()->default(0);
        $table->integer('response_code')->unsigned()->nullable();
        $table->text('response_body')->nullable();
        $table->timestamp('delivered_at')->nullable();
        $table->timestamp('next_retry_at')->nullable();
        $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

        $table->index('subscription_id');
        $table->index('status');
        $table->index('next_retry_at');

        $table->create();
        $this->schema->execute();
    }
}
```

#### Benefits

| Benefit | Description |
|---------|-------------|
| Zero configuration | Just use `Webhook::dispatch()` - tables created automatically |
| No install command | No need to run migrations manually |
| Self-healing | If tables are dropped, they're recreated on next use |
| Framework-managed | Schema stays in sync with framework version |
| Idempotent | Safe to call multiple times, only creates if missing |

#### Table Schemas

**`webhook_subscriptions`**:

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT | Primary key |
| `uuid` | VARCHAR(36) | Public identifier |
| `url` | VARCHAR(2048) | Webhook endpoint URL |
| `events` | JSON | Array of subscribed events |
| `secret` | VARCHAR(255) | HMAC signing secret |
| `is_active` | BOOLEAN | Whether subscription is active |
| `metadata` | JSON | Additional metadata |
| `created_at` | TIMESTAMP | Creation timestamp |
| `updated_at` | TIMESTAMP | Last update timestamp |

**`webhook_deliveries`**:

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT | Primary key |
| `uuid` | VARCHAR(36) | Public identifier |
| `subscription_id` | BIGINT | Foreign key to subscriptions |
| `event` | VARCHAR(255) | Event name |
| `payload` | JSON | Webhook payload |
| `status` | VARCHAR(20) | pending/delivered/failed/retrying |
| `attempts` | INT | Number of delivery attempts |
| `response_code` | INT | HTTP response code |
| `response_body` | TEXT | Response body (truncated) |
| `delivered_at` | TIMESTAMP | When successfully delivered |
| `next_retry_at` | TIMESTAMP | When to retry next |
| `created_at` | TIMESTAMP | Creation timestamp |

---

## Subscription Management

### Subscription Model

```php
<?php

namespace Glueful\Api\Webhooks;

use Glueful\Database\ORM\Model;

/**
 * Webhook Subscription Model
 *
 * @property string $uuid
 * @property string $url
 * @property array $events
 * @property string $secret
 * @property bool $is_active
 * @property array|null $metadata
 * @property \DateTimeInterface $created_at
 * @property \DateTimeInterface $updated_at
 */
class WebhookSubscription extends Model
{
    protected string $table = 'webhook_subscriptions';

    protected array $fillable = [
        'url',
        'events',
        'secret',
        'is_active',
        'metadata',
    ];

    protected array $casts = [
        'events' => 'array',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    protected array $hidden = [
        'secret',
    ];

    /**
     * Generate a new subscription secret
     */
    public static function generateSecret(): string
    {
        return 'whsec_' . bin2hex(random_bytes(32));
    }

    /**
     * Check if subscription listens to an event
     */
    public function listensTo(string $event): bool
    {
        foreach ($this->events as $pattern) {
            // Exact match
            if ($pattern === $event) {
                return true;
            }

            // Wildcard match (e.g., 'user.*' matches 'user.created')
            if (str_ends_with($pattern, '.*')) {
                $prefix = substr($pattern, 0, -2);
                if (str_starts_with($event, $prefix . '.')) {
                    return true;
                }
            }

            // All events
            if ($pattern === '*') {
                return true;
            }
        }

        return false;
    }

    /**
     * Relationship: deliveries
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'subscription_id');
    }

    /**
     * Get recent delivery stats
     */
    public function stats(int $days = 30): array
    {
        $since = now()->subDays($days);

        return [
            'total' => $this->deliveries()->where('created_at', '>=', $since)->count(),
            'delivered' => $this->deliveries()
                ->where('status', 'delivered')
                ->where('created_at', '>=', $since)
                ->count(),
            'failed' => $this->deliveries()
                ->where('status', 'failed')
                ->where('created_at', '>=', $since)
                ->count(),
        ];
    }
}
```

### Delivery Tracking Model

```php
<?php

namespace Glueful\Api\Webhooks;

use Glueful\Database\ORM\Model;

/**
 * Webhook Delivery Model
 *
 * @property string $uuid
 * @property int $subscription_id
 * @property string $event
 * @property array $payload
 * @property string $status
 * @property int $attempts
 * @property int|null $response_code
 * @property string|null $response_body
 * @property \DateTimeInterface|null $delivered_at
 * @property \DateTimeInterface|null $next_retry_at
 * @property \DateTimeInterface $created_at
 */
class WebhookDelivery extends Model
{
    protected string $table = 'webhook_deliveries';

    public const STATUS_PENDING = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RETRYING = 'retrying';

    protected array $fillable = [
        'subscription_id',
        'event',
        'payload',
        'status',
        'attempts',
        'response_code',
        'response_body',
        'delivered_at',
        'next_retry_at',
    ];

    protected array $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'response_code' => 'integer',
        'delivered_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    /**
     * Mark delivery as successful
     */
    public function markDelivered(int $statusCode, string $body): void
    {
        $this->update([
            'status' => self::STATUS_DELIVERED,
            'response_code' => $statusCode,
            'response_body' => substr($body, 0, 65535), // Limit size
            'delivered_at' => now(),
            'next_retry_at' => null,
        ]);
    }

    /**
     * Mark delivery as failed
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
            'next_retry_at' => $at,
        ]);
    }

    /**
     * Relationship: subscription
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'subscription_id');
    }
}
```

---

## Webhook Delivery

### Webhook Dispatcher

```php
<?php

namespace Glueful\Api\Webhooks;

use Glueful\Api\Webhooks\Jobs\DeliverWebhookJob;
use Glueful\Events\Event;

/**
 * Central webhook dispatcher
 */
class WebhookDispatcher
{
    public function __construct(
        private readonly WebhookPayload $payloadBuilder,
    ) {}

    /**
     * Dispatch a webhook event
     *
     * @param string $event Event name (e.g., 'user.created')
     * @param array $data Event data
     * @param array $options Additional options
     */
    public function dispatch(string $event, array $data, array $options = []): array
    {
        $deliveries = [];

        // Find active subscriptions for this event
        $subscriptions = WebhookSubscription::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn($sub) => $sub->listensTo($event));

        foreach ($subscriptions as $subscription) {
            $delivery = $this->createDelivery($subscription, $event, $data);
            $deliveries[] = $delivery;

            // Queue the delivery job
            DeliverWebhookJob::dispatch($delivery)->onQueue('webhooks');
        }

        // Dispatch internal event
        Event::dispatch(new Events\WebhookDispatched($event, count($deliveries)));

        return $deliveries;
    }

    /**
     * Create a delivery record
     */
    private function createDelivery(
        WebhookSubscription $subscription,
        string $event,
        array $data
    ): WebhookDelivery {
        return WebhookDelivery::create([
            'subscription_id' => $subscription->id,
            'event' => $event,
            'payload' => $this->payloadBuilder->build($event, $data),
            'status' => WebhookDelivery::STATUS_PENDING,
            'attempts' => 0,
        ]);
    }
}
```

### Delivery Job

```php
<?php

namespace Glueful\Api\Webhooks\Jobs;

use Glueful\Api\Webhooks\WebhookDelivery;
use Glueful\Api\Webhooks\WebhookSignature;
use Glueful\Api\Webhooks\Events\WebhookDelivered;
use Glueful\Api\Webhooks\Events\WebhookFailed;
use Glueful\Events\Event;
use Glueful\Queue\Job;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class DeliverWebhookJob extends Job
{
    public string $queue = 'webhooks';
    public int $tries = 5;
    public array $backoff = [60, 300, 1800, 7200, 43200]; // 1m, 5m, 30m, 2h, 12h

    private const TIMEOUT = 30;

    public function __construct(
        private readonly WebhookDelivery $delivery,
    ) {}

    public function handle(): void
    {
        $subscription = $this->delivery->subscription;

        if (!$subscription->is_active) {
            $this->delivery->markFailed(0, 'Subscription disabled');
            return;
        }

        $this->delivery->increment('attempts');

        try {
            $response = $this->sendRequest($subscription);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->delivery->markDelivered($statusCode, $body);
                Event::dispatch(new WebhookDelivered($this->delivery));
            } else {
                $this->handleFailure($statusCode, $body);
            }
        } catch (RequestException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();
            $this->handleFailure($statusCode, $body);
        }
    }

    private function sendRequest(WebhookSubscription $subscription): \Psr\Http\Message\ResponseInterface
    {
        $payload = json_encode($this->delivery->payload);
        $timestamp = time();
        $signature = WebhookSignature::generate(
            $payload,
            $subscription->secret,
            $timestamp
        );

        $client = new Client(['timeout' => self::TIMEOUT]);

        return $client->post($subscription->url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Webhook-ID' => $this->delivery->uuid,
                'X-Webhook-Event' => $this->delivery->event,
                'X-Webhook-Timestamp' => $timestamp,
                'X-Webhook-Signature' => $signature,
                'User-Agent' => 'Glueful-Webhooks/1.0',
            ],
            'body' => $payload,
        ]);
    }

    private function handleFailure(int $statusCode, string $body): void
    {
        if ($this->delivery->attempts >= $this->tries) {
            $this->delivery->markFailed($statusCode, $body);
            Event::dispatch(new WebhookFailed($this->delivery));
        } else {
            $delay = $this->backoff[$this->delivery->attempts - 1] ?? 43200;
            $this->delivery->scheduleRetry(now()->addSeconds($delay));
            $this->release($delay);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->delivery->markFailed(0, $exception->getMessage());
        Event::dispatch(new WebhookFailed($this->delivery));
    }
}
```

---

## Security & Signatures

### Signature Generation

```php
<?php

namespace Glueful\Api\Webhooks;

/**
 * Webhook signature generation and verification
 */
class WebhookSignature
{
    private const ALGORITHM = 'sha256';

    /**
     * Generate signature for payload
     *
     * @param string $payload JSON payload
     * @param string $secret Webhook secret
     * @param int $timestamp Unix timestamp
     * @return string Signature header value
     */
    public static function generate(string $payload, string $secret, int $timestamp): string
    {
        $signedPayload = "{$timestamp}.{$payload}";
        $signature = hash_hmac(self::ALGORITHM, $signedPayload, $secret);

        return "t={$timestamp},v1={$signature}";
    }

    /**
     * Verify signature from request
     *
     * @param string $payload Request body
     * @param string $signatureHeader X-Webhook-Signature header value
     * @param string $secret Webhook secret
     * @param int $tolerance Timestamp tolerance in seconds (default 5 minutes)
     * @return bool True if valid
     */
    public static function verify(
        string $payload,
        string $signatureHeader,
        string $secret,
        int $tolerance = 300
    ): bool {
        $parts = self::parseSignature($signatureHeader);

        if ($parts === null) {
            return false;
        }

        ['timestamp' => $timestamp, 'signatures' => $signatures] = $parts;

        // Check timestamp tolerance
        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        // Generate expected signature
        $signedPayload = "{$timestamp}.{$payload}";
        $expected = hash_hmac(self::ALGORITHM, $signedPayload, $secret);

        // Check if any signature matches
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse signature header
     *
     * @param string $header
     * @return array{timestamp: int, signatures: array<string>}|null
     */
    private static function parseSignature(string $header): ?array
    {
        $items = explode(',', $header);
        $timestamp = null;
        $signatures = [];

        foreach ($items as $item) {
            [$key, $value] = explode('=', trim($item), 2) + [null, null];

            if ($key === 't') {
                $timestamp = (int) $value;
            } elseif (str_starts_with($key, 'v')) {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return null;
        }

        return ['timestamp' => $timestamp, 'signatures' => $signatures];
    }
}
```

### Webhook Facade

```php
<?php

namespace Glueful\Api\Webhooks;

/**
 * Webhook facade for easy dispatching
 *
 * @example
 * Webhook::dispatch('user.created', ['user' => $user->toArray()]);
 */
class Webhook
{
    private static ?WebhookDispatcher $dispatcher = null;

    /**
     * Dispatch a webhook
     */
    public static function dispatch(string $event, array $data = []): array
    {
        return self::getDispatcher()->dispatch($event, $data);
    }

    /**
     * Subscribe to an event
     */
    public static function subscribe(
        string|array $events,
        string $url,
        array $options = []
    ): WebhookSubscription {
        return WebhookSubscription::create([
            'url' => $url,
            'events' => (array) $events,
            'secret' => WebhookSubscription::generateSecret(),
            'is_active' => $options['active'] ?? true,
            'metadata' => $options['metadata'] ?? null,
        ]);
    }

    /**
     * Get all subscriptions for an event
     */
    public static function subscriptionsFor(string $event): array
    {
        return WebhookSubscription::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn($sub) => $sub->listensTo($event))
            ->values()
            ->all();
    }

    private static function getDispatcher(): WebhookDispatcher
    {
        if (self::$dispatcher === null) {
            self::$dispatcher = app(WebhookDispatcher::class);
        }

        return self::$dispatcher;
    }
}
```

---

## Payload Structure

### Standard Webhook Payload

```json
{
    "id": "wh_evt_01HXYZ123456789ABCDEF",
    "event": "user.created",
    "created_at": "2026-01-22T12:00:00Z",
    "data": {
        "user": {
            "id": "usr_01HXYZ987654321FEDCBA",
            "email": "john@example.com",
            "name": "John Doe",
            "created_at": "2026-01-22T12:00:00Z"
        }
    },
    "metadata": {
        "api_version": "v2",
        "source": "api"
    }
}
```

### Webhook Headers

| Header | Description | Example |
|--------|-------------|---------|
| `X-Webhook-ID` | Unique delivery ID | `wh_del_01HXYZ...` |
| `X-Webhook-Event` | Event name | `user.created` |
| `X-Webhook-Timestamp` | Unix timestamp | `1706011200` |
| `X-Webhook-Signature` | HMAC signature | `t=1706011200,v1=abc...` |
| `Content-Type` | Always JSON | `application/json` |
| `User-Agent` | Glueful identifier | `Glueful-Webhooks/1.0` |

---

## Console Commands

### webhook:list

```bash
php glueful webhook:list

┌────────────────────┬─────────────────────────────────────┬──────────────────────────┬────────┐
│ ID                 │ URL                                 │ Events                   │ Active │
├────────────────────┼─────────────────────────────────────┼──────────────────────────┼────────┤
│ wh_sub_01HXYZ...   │ https://example.com/webhooks        │ user.*, order.completed  │ Yes    │
│ wh_sub_01HXYZ...   │ https://acme.com/api/hooks          │ payment.*                │ Yes    │
│ wh_sub_01HXYZ...   │ https://old.example.com/hooks       │ *                        │ No     │
└────────────────────┴─────────────────────────────────────┴──────────────────────────┴────────┘
```

### webhook:test

```bash
php glueful webhook:test https://example.com/webhooks --event=user.created

Testing webhook endpoint: https://example.com/webhooks
Event: user.created

Sending test payload...
✓ Response: 200 OK (245ms)
✓ Signature verification available

Endpoint is ready to receive webhooks!
```

### webhook:retry

```bash
php glueful webhook:retry --failed --since="1 hour ago"

Retrying 5 failed deliveries from the last hour...
✓ wh_del_01HXYZ... → 200 OK
✓ wh_del_01HXYZ... → 200 OK
✗ wh_del_01HXYZ... → 503 Service Unavailable (will retry in 30m)
✓ wh_del_01HXYZ... → 200 OK
✓ wh_del_01HXYZ... → 200 OK

4/5 deliveries successful
```

---

## REST API for Subscriptions

### Endpoints

```
POST   /api/webhooks/subscriptions           Create subscription
GET    /api/webhooks/subscriptions           List subscriptions
GET    /api/webhooks/subscriptions/{id}      Get subscription
PATCH  /api/webhooks/subscriptions/{id}      Update subscription
DELETE /api/webhooks/subscriptions/{id}      Delete subscription
POST   /api/webhooks/subscriptions/{id}/test Send test webhook
GET    /api/webhooks/deliveries              List deliveries
GET    /api/webhooks/deliveries/{id}         Get delivery details
POST   /api/webhooks/deliveries/{id}/retry   Retry delivery
```

### Example Requests

```bash
# Create subscription
curl -X POST /api/webhooks/subscriptions \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "url": "https://example.com/webhooks",
    "events": ["user.created", "user.updated", "order.*"]
  }'

# Response
{
  "data": {
    "id": "wh_sub_01HXYZ...",
    "url": "https://example.com/webhooks",
    "events": ["user.created", "user.updated", "order.*"],
    "secret": "whsec_a1b2c3d4e5f6...",
    "is_active": true,
    "created_at": "2026-01-22T12:00:00Z"
  }
}
```

---

## Integration with Events

### Webhookable Trait

```php
<?php

namespace Glueful\Api\Webhooks\Concerns;

/**
 * Make an event dispatchable as a webhook
 */
trait Webhookable
{
    /**
     * Get the webhook event name
     */
    public function webhookEventName(): string
    {
        // Default: class name to dot notation
        // UserCreated → user.created
        $class = class_basename(static::class);
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1.$2', $class));
    }

    /**
     * Get the webhook payload data
     */
    public function webhookPayload(): array
    {
        return get_object_vars($this);
    }

    /**
     * Should this event trigger webhooks?
     */
    public function shouldDispatchWebhook(): bool
    {
        return true;
    }
}
```

### Usage in Events

```php
<?php

namespace App\Events;

use Glueful\Events\BaseEvent;
use Glueful\Api\Webhooks\Concerns\Webhookable;

class UserCreated extends BaseEvent
{
    use Webhookable;

    public function __construct(
        public readonly array $user,
    ) {
        parent::__construct();
    }

    public function webhookEventName(): string
    {
        return 'user.created';
    }

    public function webhookPayload(): array
    {
        return ['user' => $this->user];
    }
}
```

---

## Implementation Phases

### Phase 1: Core Infrastructure (Week 1) ✅ COMPLETE

**Deliverables:**
- [x] Auto-migration for subscriptions and deliveries tables
- [x] `WebhookSubscription` ORM model with wildcard event matching
- [x] `WebhookDelivery` ORM model with status tracking
- [x] `WebhookSignature` class (HMAC-SHA256, Stripe-style format)
- [x] Configuration in `config/api.php`

**Acceptance Criteria:**
```php
$subscription = WebhookSubscription::create([
    'url' => 'https://example.com/webhooks',
    'events' => ['user.created'],
    'secret' => WebhookSubscription::generateSecret(),
]);

$signature = WebhookSignature::generate($payload, $subscription->secret, time());
```

### Phase 2: Delivery System (Week 2) ✅ COMPLETE

**Deliverables:**
- [x] `WebhookDispatcher` class with auto-migration
- [x] `DeliverWebhookJob` queue job (uses Glueful HTTP Client)
- [x] `WebhookPayload` builder
- [x] Retry logic with exponential backoff (1m, 5m, 30m, 2h, 12h)
- [x] Delivery tracking

**Acceptance Criteria:**
```php
Webhook::dispatch('user.created', ['user' => $user->toArray()]);
// Creates delivery record and queues job
```

### Phase 3: Event Integration (Week 2-3) ✅ COMPLETE

**Deliverables:**
- [x] `DispatchesWebhooks` trait
- [x] `#[Webhookable]` PHP 8 attribute
- [x] `WebhookEventListener`
- [x] `WebhookDispatchedEvent`
- [x] `Webhook` facade

**Acceptance Criteria:**
```php
// Automatic webhook on event dispatch
Event::dispatch(new UserCreated($user));
// Triggers webhook if subscriptions exist
```

### Phase 4: Management & CLI (Week 3-4) ✅ COMPLETE

**Deliverables:**
- [x] REST API for subscriptions (`WebhookController`)
- [x] `webhook:list` command
- [x] `webhook:test` command
- [x] `webhook:retry` command

**Acceptance Criteria:**
```bash
php glueful webhook:list
php glueful webhook:test https://example.com/webhooks --event=user.created
php glueful webhook:retry --failed --since="1 hour ago"
```

---

## Testing Strategy

### Unit Tests

```php
<?php

namespace Glueful\Tests\Unit\Api\Webhooks;

use PHPUnit\Framework\TestCase;
use Glueful\Api\Webhooks\WebhookSignature;

class WebhookSignatureTest extends TestCase
{
    public function testGeneratesValidSignature(): void
    {
        $payload = '{"event":"test"}';
        $secret = 'whsec_test123';
        $timestamp = 1706011200;

        $signature = WebhookSignature::generate($payload, $secret, $timestamp);

        $this->assertStringStartsWith('t=1706011200,v1=', $signature);
    }

    public function testVerifiesValidSignature(): void
    {
        $payload = '{"event":"test"}';
        $secret = 'whsec_test123';
        $timestamp = time();

        $signature = WebhookSignature::generate($payload, $secret, $timestamp);

        $this->assertTrue(
            WebhookSignature::verify($payload, $signature, $secret)
        );
    }

    public function testRejectsExpiredTimestamp(): void
    {
        $payload = '{"event":"test"}';
        $secret = 'whsec_test123';
        $timestamp = time() - 600; // 10 minutes ago

        $signature = WebhookSignature::generate($payload, $secret, $timestamp);

        $this->assertFalse(
            WebhookSignature::verify($payload, $signature, $secret, 300)
        );
    }
}
```

### Integration Tests

```php
<?php

namespace Glueful\Tests\Integration\Api\Webhooks;

use Glueful\Testing\TestCase;
use Glueful\Api\Webhooks\Webhook;
use Glueful\Api\Webhooks\WebhookSubscription;

class WebhookIntegrationTest extends TestCase
{
    public function testDispatchesWebhookToSubscribers(): void
    {
        // Create subscription
        $subscription = WebhookSubscription::create([
            'url' => 'https://example.com/webhooks',
            'events' => ['user.created'],
            'secret' => WebhookSubscription::generateSecret(),
            'is_active' => true,
        ]);

        // Mock HTTP client
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        // Dispatch webhook
        $deliveries = Webhook::dispatch('user.created', ['user' => ['id' => 1]]);

        // Assert delivery created
        $this->assertCount(1, $deliveries);
        $this->assertEquals('pending', $deliveries[0]->status);
    }
}
```

---

## Configuration Reference

```php
// config/api.php
return [
    'webhooks' => [
        'enabled' => true,

        // Queue settings
        'queue' => 'webhooks',
        'connection' => null, // Use default

        // Signature settings
        'signature_header' => 'X-Webhook-Signature',
        'signature_algorithm' => 'sha256',

        // Request settings
        'timeout' => 30,
        'user_agent' => 'Glueful-Webhooks/1.0',

        // Retry settings
        'retry' => [
            'max_attempts' => 5,
            'backoff' => [60, 300, 1800, 7200, 43200], // seconds
        ],

        // Cleanup settings
        'cleanup' => [
            'keep_successful_days' => 7,
            'keep_failed_days' => 30,
        ],

        // Events that can trigger webhooks
        'events' => [
            'user.created' => \App\Events\UserCreated::class,
            'user.updated' => \App\Events\UserUpdated::class,
            'user.deleted' => \App\Events\UserDeleted::class,
            'order.created' => \App\Events\OrderCreated::class,
            'order.completed' => \App\Events\OrderCompleted::class,
        ],
    ],
];
```

---

## Security Considerations

1. **Secret Management**: Secrets are stored hashed; display only on creation
2. **HTTPS Only**: Reject webhook URLs that don't use HTTPS
3. **Timestamp Validation**: Reject requests with timestamps > 5 minutes old
4. **IP Whitelisting**: Optional feature for subscriber verification
5. **Rate Limiting**: Per-subscription rate limits to prevent abuse
6. **Payload Size**: Maximum payload size limit (1MB default)
