<?php

use Illuminate\Support\Facades\Route;
use Korbytes\Payments\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| Payment Webhook Routes
|--------------------------------------------------------------------------
|
| These routes handle incoming webhooks from payment providers.
| The route prefix and middleware can be configured in config/payments.php
|
*/

Route::group([
    'prefix' => config('payments.webhooks.prefix', 'payments/webhooks'),
    'middleware' => config('payments.webhooks.middleware', ['api']),
], function () {
    Route::post('/{provider}', [WebhookController::class, 'handle'])
        ->name('payments.webhooks.handle');
});
