<?php

return [
    'auth' => [
        'access_ttl_minutes' => env('ACCESS_TOKEN_TTL_MINUTES', 10080),
        // Rotating refresh sessions.
        'refresh_ttl_days' => env('REFRESH_TOKEN_TTL_DAYS', 30),
        'static_verification_code' => env('STATIC_VERIFICATION_CODE', '1234'),
    ],

    'booking' => [
        // How long a booking request stays answerable.
        'request_ttl_hours' => env('BOOKING_REQUEST_TTL_HOURS', 24),
        'availability_cache_store' => env('AVAILABILITY_CACHE_STORE', 'redis'),
        'availability_cache_seconds' => (int) env('AVAILABILITY_CACHE_SECONDS', 20),
        'availability_lock_seconds' => (int) env('AVAILABILITY_LOCK_SECONDS', 30),
        'availability_lock_wait_seconds' => (int) env('AVAILABILITY_LOCK_WAIT_SECONDS', 2),
    ],

    'checkout' => [
        'quote_ttl_minutes' => env('CHECKOUT_QUOTE_TTL_MINUTES', 15),
    ],

    'offers' => [
        'redemption_ttl_minutes' => env('OFFER_REDEMPTION_TTL_MINUTES', 30),
    ],

];
