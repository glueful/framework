<?php

/**
 * Core Capability Persistence Switches
 *
 * Core migrations are always registered (since 1.79); these flags only decide whether a capability
 * PERSISTS to its tables at runtime. Capabilities whose backing is implied by their own driver config
 * are not listed here (locks → lock.default, queue → queue.default, uploads → uploads.enabled).
 */

return [
    // Notifications persistence (notifications, deliveries, preferences, templates, retry queue).
    // false: no database channel, and scheduling a notification throws.
    'notifications' => env('NOTIFICATIONS_DATABASE_STORE', true),
];
