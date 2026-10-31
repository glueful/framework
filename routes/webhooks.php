<?php

use Glueful\Routing\Router;
use Glueful\Api\Webhooks\Http\Controllers\WebhookController;

/**
 * @var Router $router Router instance injected by RouteManifest::load()
 *
 * Outbound-webhook management (subscriptions + delivery records). Mounted under the API prefix
 * (e.g. /api/v1/webhooks). `auth` guarantees an authenticated principal; the controller then
 * requires `system.config.view` to read and `system.config.edit` to change anything (the same
 * tiers as the in-admin extensions manager). `{id}` is the subscription / delivery uuid.
 */
$router->group(['prefix' => '/webhooks'], function (Router $router) {
    $router->get('/subscriptions', [WebhookController::class, 'listSubscriptions'])
        ->middleware(['auth', 'rate_limit:60,60']);

    $router->post('/subscriptions', [WebhookController::class, 'createSubscription'])
        ->middleware(['auth', 'rate_limit:20,60']);

    $router->get('/subscriptions/{id}', [WebhookController::class, 'getSubscription'])
        ->middleware(['auth', 'rate_limit:60,60']);

    $router->patch('/subscriptions/{id}', [WebhookController::class, 'updateSubscription'])
        ->middleware(['auth', 'rate_limit:20,60']);

    $router->delete('/subscriptions/{id}', [WebhookController::class, 'deleteSubscription'])
        ->middleware(['auth', 'rate_limit:20,60']);

    $router->post('/subscriptions/{id}/rotate-secret', [WebhookController::class, 'rotateSecret'])
        ->middleware(['auth', 'rate_limit:10,60']);

    $router->post('/subscriptions/{id}/test', [WebhookController::class, 'testSubscription'])
        ->middleware(['auth', 'rate_limit:10,60']);

    $router->get('/subscriptions/{id}/stats', [WebhookController::class, 'getSubscriptionStats'])
        ->middleware(['auth', 'rate_limit:60,60']);

    $router->get('/deliveries', [WebhookController::class, 'listDeliveries'])
        ->middleware(['auth', 'rate_limit:60,60']);

    $router->get('/deliveries/{id}', [WebhookController::class, 'getDelivery'])
        ->middleware(['auth', 'rate_limit:60,60']);

    $router->post('/deliveries/{id}/retry', [WebhookController::class, 'retryDelivery'])
        ->middleware(['auth', 'rate_limit:20,60']);
});
