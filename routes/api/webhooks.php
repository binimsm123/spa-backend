<?php

use App\Http\Controllers\Api\WebhookController;
use App\Http\Middleware\HandleEsewaSuccess;
use Illuminate\Support\Facades\Route;

Route::get('esewa/success', [WebhookController::class, 'esewaSuccess'])
    ->middleware(['throttle:payment', HandleEsewaSuccess::class])
    ->name('payments.esewa.success');

Route::get('esewa/failure', [WebhookController::class, 'esewaFailure'])
    ->middleware('throttle:payment')
    ->name('payments.esewa.failure');
