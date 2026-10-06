<?php

return [
    'auth' => [
        // Short-lived access tokens (Sanctum ability config can extend this).
        'access_ttl_minutes' => env('ACCESS_TOKEN_TTL_MINUTES', 30),
        // Rotating refresh sessions.
        'refresh_ttl_days' => env('REFRESH_TOKEN_TTL_DAYS', 30),
        'static_verification_code' => env('STATIC_VERIFICATION_CODE', '1234'),
    ],

    'booking' => [
        // How long a booking request stays answerable.
        'request_ttl_hours' => env('BOOKING_REQUEST_TTL_HOURS', 24),
    ],

    'checkout' => [
        'quote_ttl_minutes' => env('CHECKOUT_QUOTE_TTL_MINUTES', 15),
    ],

    'offers' => [
        'redemption_ttl_minutes' => env('OFFER_REDEMPTION_TTL_MINUTES', 30),
    ],

    'payments' => [
        // HMAC secret for gateway webhook signature verification.
        'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET', 'change-me-in-production'),
    ],
];
