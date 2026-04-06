<?php

use Illuminate\Support\Facades\Route;
use Skeylup\LaravelPipedrive\Http\Controllers\PipedriveWebhookController;
use Skeylup\LaravelPipedrive\Http\Middleware\AuthorizeWebhookHealth;
use Skeylup\LaravelPipedrive\Http\Middleware\VerifyPipedriveWebhook;

/*
|--------------------------------------------------------------------------
| Pipedrive Webhook Routes
|--------------------------------------------------------------------------
|
| These routes handle incoming webhooks from Pipedrive for real-time
| data synchronization. The routes are automatically registered by
| the service provider.
|
*/

// Main webhook endpoint
Route::post(
    config('pipedrive.webhooks.route.path', 'pipedrive/webhook'),
    [PipedriveWebhookController::class, 'handle']
)->middleware(VerifyPipedriveWebhook::class)
    ->name(config('pipedrive.webhooks.route.name', 'pipedrive.webhook'));

// Health check endpoint for webhook URL validation
// Uses special middleware that allows access for both authorized users AND Pipedrive servers
Route::get(
    config('pipedrive.webhooks.route.path', 'pipedrive/webhook').'/health',
    [PipedriveWebhookController::class, 'health']
)->middleware(['web', AuthorizeWebhookHealth::class])
    ->name('pipedrive.webhook.health');
