<?php

use App\Http\Controllers\Api\Business\BusinessBookingController;
use App\Http\Controllers\Api\Business\BusinessOfferController;
use App\Http\Controllers\Api\Business\BusinessProfileController;
use App\Http\Controllers\Api\Business\BusinessServiceController;
use App\Http\Controllers\Api\Business\BusinessStaffController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])->prefix('business')->group(function (): void {
    Route::get('{business}/documents/{document}', [BusinessProfileController::class, 'document'])->whereUlid('business');
    Route::get('my', [BusinessProfileController::class, 'myBusinesses']);
    Route::get('{business}', [BusinessProfileController::class, 'show'])->whereUlid('business');
    Route::patch('{business}', [BusinessProfileController::class, 'update'])->whereUlid('business');

    // Hours + closures
    Route::put('{business}/hours', [BusinessProfileController::class, 'updateHours'])->whereUlid('business');
    Route::post('{business}/closures', [BusinessProfileController::class, 'addClosures'])->whereUlid('business');
    Route::delete('{business}/closures/{closure}', [BusinessProfileController::class, 'removeClosure'])->whereUlid('business')->whereUlid('closure');

    // Services
    Route::get('{business}/services', [BusinessServiceController::class, 'index'])->whereUlid('business');
    Route::post('{business}/services', [BusinessServiceController::class, 'store'])->whereUlid('business');
    Route::patch('{business}/services/{service}', [BusinessServiceController::class, 'update'])->whereUlid('business')->whereUlid('service');

    // Staff
    Route::get('{business}/staff', [BusinessStaffController::class, 'index'])->whereUlid('business');
    Route::post('{business}/staff/invite', [BusinessStaffController::class, 'invite'])->whereUlid('business');
    Route::post('{business}/staff/{user}/deactivate', [BusinessStaffController::class, 'deactivate'])->whereUlid('business')->whereUlid('user');

    // Booking requests + bookings
    Route::get('{business}/bookings/requests', [BusinessBookingController::class, 'requests'])->whereUlid('business');
    Route::post('{business}/bookings/requests/{booking_request}/propose', [BusinessBookingController::class, 'propose'])->whereUlid('business')->whereUlid('booking_request');
    Route::post('{business}/bookings/requests/{booking_request}/decline', [BusinessBookingController::class, 'decline'])->whereUlid('business')->whereUlid('booking_request');
    Route::get('{business}/bookings', [BusinessBookingController::class, 'index'])->whereUlid('business');
    Route::patch('{business}/bookings/{booking}/status', [BusinessBookingController::class, 'updateStatus'])->whereUlid('business')->whereUlid('booking');

    // Offers
    Route::get('{business}/offers', [BusinessOfferController::class, 'index'])->whereUlid('business');
    Route::post('{business}/offers', [BusinessOfferController::class, 'store'])->whereUlid('business');
    Route::patch('{business}/offers/{offer}', [BusinessOfferController::class, 'update'])->whereUlid('business')->whereUlid('offer');
    Route::delete('{business}/offers/{offer}', [BusinessOfferController::class, 'destroy'])->whereUlid('business')->whereUlid('offer');
});
