<?php

use Conceptlz\AdobeSign\Laravel\Http\Controllers\OAuthController;
use Conceptlz\AdobeSign\Laravel\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

if (config('adobe-sign.oauth.routes', true)) {
    Route::middleware(config('adobe-sign.oauth.route_middleware', ['web', 'auth']))
        ->prefix(config('adobe-sign.oauth.route_prefix', 'adobe-sign/oauth'))
        ->name('adobe-sign.oauth.')
        ->group(function () {
            Route::get('redirect', [OAuthController::class, 'redirect'])->name('redirect');
            Route::get('callback', [OAuthController::class, 'callback'])->name('callback');
        });
}

if (config('adobe-sign.webhooks.enabled', true)) {
    Route::middleware(config('adobe-sign.webhooks.middleware', []))
        ->match(['GET', 'POST'], config('adobe-sign.webhooks.path', 'adobe-sign/webhook'), WebhookController::class)
        ->name('adobe-sign.webhook');
}
