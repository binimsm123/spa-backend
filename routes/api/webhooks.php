<?php

use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

// Payment gateway webhooks are signature-verified and idempotent.
// They must stay unauthenticated (gateway callbacks) but are rate limited.
Route::middleware('throttle:payment')->prefix('webhooks')->group(function (): void {
    Route::post('payments/{gateway}', [WebhookController::class, 'payment'])->whereIn('gateway', ['esewa', 'khalti', 'mypay']);
});
