<?php

use App\Http\Controllers\Api\Business\BusinessBookingController;
use App\Http\Controllers\Api\Business\BusinessOfferController;
use App\Http\Controllers\Api\Business\BusinessOnboardingController;
use App\Http\Controllers\Api\Business\BusinessProfileController;
use App\Http\Controllers\Api\Business\BusinessRosterController;
use App\Http\Controllers\Api\Business\BusinessServiceController;
use App\Http\Controllers\Api\Business\BusinessStaffController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])->prefix('business')->group(function (): void {
    Route::get('onboarding', [BusinessOnboardingController::class, 'show']);
    Route::post('onboarding', [BusinessOnboardingController::class, 'submit'])->name('business.onboarding.submit');
    Route::post('onboarding/resubmit', [BusinessOnboardingController::class, 'submit'])->name('business.onboarding.resubmit');
    Route::get('{business}/documents/{document}', [BusinessProfileController::class, 'document'])->whereUlid('business')->name('business.documents.show');
    Route::get('my', [BusinessProfileController::class, 'myBusinesses']);
    Route::get('{business}', [BusinessProfileController::class, 'show'])->whereUlid('business');
    Route::patch('{business}', [BusinessProfileController::class, 'update'])->whereUlid('business');
    Route::patch('{business}/toggleStatus', [BusinessProfileController::class, 'update'])->whereUlid('business');
    // Hours + closures
    Route::put('{business}/hours', [BusinessProfileController::class, 'updateHours'])->whereUlid('business');
    Route::post('{business}/closures', [BusinessProfileController::class, 'addClosures'])->whereUlid('business');
    Route::delete('{business}/closures/{closure}', [BusinessProfileController::class, 'removeClosure'])->whereUlid('business')->whereUlid('closure');

    // Services
    Route::get('{business}/service-categories', [BusinessServiceController::class, 'categories'])->whereUlid('business');
    Route::get('{business}/services', [BusinessServiceController::class, 'index'])->whereUlid('business');
    Route::post('{business}/services', [BusinessServiceController::class, 'store'])->whereUlid('business');
    Route::get('{business}/services/{service}', [BusinessServiceController::class, 'show'])->whereUlid('business')->whereUlid('service');
    Route::patch('{business}/services/{service}', [BusinessServiceController::class, 'update'])->whereUlid('business')->whereUlid('service');
    Route::delete('{business}/services/{service}', [BusinessServiceController::class, 'destroy'])->whereUlid('business')->whereUlid('service');

    // Roster records do not grant account access or business membership.
    // Route::get('{business}/staff-roster', [BusinessRosterController::class, 'index'])->whereUlid('business');
    // Route::post('{business}/staff-roster', [BusinessRosterController::class, 'store'])->whereUlid('business');
    // Route::get('{business}/staff-roster/{staff}', [BusinessRosterController::class, 'show'])->whereUlid('business')->whereUlid('staff');
    // Route::patch('{business}/staff-roster/{staff}', [BusinessRosterController::class, 'update'])->whereUlid('business')->whereUlid('staff');
    // Route::delete('{business}/staff-roster/{staff}', [BusinessRosterController::class, 'destroy'])->whereUlid('business')->whereUlid('staff');
    // Route::get('{business}/staff-roster/{staff}/avatar', [BusinessRosterController::class, 'avatar'])->whereUlid('business')->whereUlid('staff')->name('business.staff-roster.avatar');

    // Staff
    Route::get('{business}/staff', [BusinessStaffController::class, 'index'])->whereUlid('business');
    Route::post('{business}/staff/invite', [BusinessStaffController::class, 'invite'])->whereUlid('business');
    Route::patch('{business}/staff/{user}', [BusinessStaffController::class, 'update'])->whereUlid('business')->whereUlid('user');
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
