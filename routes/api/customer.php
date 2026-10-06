<?php

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OfferController;
use App\Http\Controllers\Api\ReferralController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    // Profile + location
    Route::get('users/me', [MeController::class, 'me']);
    Route::patch('users/me', [MeController::class, 'updateMe']);
    Route::get('locations', [MeController::class, 'locations']);
    Route::patch('users/me/location', [MeController::class, 'updateLocation']);

    // Home + discovery
    Route::get('home', [HomeController::class, 'home']);
    Route::get('businesses', [HomeController::class, 'businesses']);
    Route::get('businesses/{business}', [HomeController::class, 'businessDetail']);

    // Availability + booking flow
    Route::get('businesses/{business}/services/{service}/availability', [BookingController::class, 'availability']);
    Route::get('bookings/requests/{booking_request}', [BookingController::class, 'showRequest'])->whereUlid('booking_request');
    Route::post('bookings/requests', [BookingController::class, 'createRequest'])->middleware('throttle:booking');
    Route::post('bookings/requests/{booking_request}/confirm', [BookingController::class, 'confirmRequest'])->whereUlid('booking_request')->middleware('throttle:booking');
    Route::get('bookings', [BookingController::class, 'index']);
    Route::get('bookings/{booking}', [BookingController::class, 'show'])->whereUlid('booking');
    Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->whereUlid('booking');

    // Checkout + payment
    Route::get('bookings/{booking}/checkout', [CheckoutController::class, 'summary'])->whereUlid('booking');
    Route::post('bookings/{booking}/checkout/quote', [CheckoutController::class, 'quote'])->whereUlid('booking')->middleware('throttle:payment');
    Route::post('bookings/{booking}/checkout', [CheckoutController::class, 'start'])->whereUlid('booking')->middleware('throttle:payment');
    Route::get('bookings/{booking}/completion', [CheckoutController::class, 'completion'])->whereUlid('booking');
    Route::post('bookings/{booking}/review', [CheckoutController::class, 'review'])->whereUlid('booking');
    Route::post('bookings/{booking}/tip/checkout', [CheckoutController::class, 'tip'])->whereUlid('booking')->middleware('throttle:payment');

    // Offers
    Route::get('offers', [OfferController::class, 'index']);
    Route::get('offers/{offer}', [OfferController::class, 'show'])->whereUlid('offer');
    Route::post('offers/{offer}/redeem', [OfferController::class, 'redeem'])->whereUlid('offer')->middleware('throttle:booking');

    // Referrals + rewards
    Route::get('referrals/me', [ReferralController::class, 'me']);
    Route::post('referrals/me/share', [ReferralController::class, 'share']);
    Route::get('referrals/me/rewards', [ReferralController::class, 'rewards']);

    // Notifications
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::patch('notifications/{notification}/read', [NotificationController::class, 'markRead'])->whereUlid('notification');
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
});
