<?php

use Illuminate\Support\Facades\Schedule;

// Expire stale booking requests every 5 minutes.
Schedule::call(function (): void {
    app(\App\Services\BookingService::class)->expireStaleRequests();
})->everyFiveMinutes()->name('booking-requests:expire')->withoutOverlapping();

// Expire unpaid bookings whose appointment time has passed, hourly.
Schedule::call(function (): void {
    app(\App\Services\BookingService::class)->expireStaleBookings();
})->hourly()->name('bookings:expire-unpaid')->withoutOverlapping();

// Release expired offer redemption reservations, every 15 minutes.
Schedule::call(function (): void {
    \App\Models\OfferRedemption::query()
        ->where('status', 'reserved')
        ->where('expires_at', '<', now())
        ->update(['status' => 'released']);
})->everyFifteenMinutes()->name('offers:release-expired-reservations')->withoutOverlapping();
