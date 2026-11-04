<?php

use App\Http\Controllers\Api\Business\BusinessBookingController;
use App\Http\Controllers\Api\Business\BusinessOfferController;
use App\Http\Controllers\Api\Business\BusinessOnboardingController;
use App\Http\Controllers\Api\Business\BusinessProfileController;
use App\Http\Controllers\Api\Business\BusinessServiceController;
use App\Http\Controllers\Api\Business\BusinessStaffController;
use App\Http\Controllers\Api\Business\BusinessStaffHoursController;
use App\Http\Controllers\Api\Business\BusinessStaffLeaveController;
use App\Http\Controllers\Api\Business\BusinessStaffServiceController;
use App\Models\Business;
use App\Models\Service;
use App\Models\StaffLeave;
use App\Models\User;
use App\Support\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])->prefix('business')->group(function (): void {
    Route::get('onboarding', [BusinessOnboardingController::class, 'show']);
    Route::post('onboarding', [BusinessOnboardingController::class, 'submit'])->name('business.onboarding.submit');
    Route::post('onboarding/resubmit', [BusinessOnboardingController::class, 'submit'])->name('business.onboarding.resubmit');
    Route::get('{business}/documents/{document}', [BusinessProfileController::class, 'document'])->whereUlid('business')->name('business.documents.show');
    Route::get('my', [BusinessProfileController::class, 'myBusinesses']);
    Route::get('{business}', [BusinessProfileController::class, 'show'])->whereUlid('business');
    Route::patch('{business}', [BusinessProfileController::class, 'update'])->whereUlid('business');
    Route::patch('{business}/toggleStatus', [BusinessProfileController::class, 'toggleStatus'])->whereUlid('business');
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
    Route::missing(function (Request $request, ModelNotFoundException $exception): never {
        throw ApiException::notFound(match ($exception->getModel()) {
            Business::class => 'Business not found.',
            User::class => 'Staff user not found in this business.',
            Service::class => 'Service not found in this business.',
            StaffLeave::class => 'Staff leave not found in this business.',
            default => 'Resource not found.',
        });
    })->group(function (): void {
        Route::get('{business}/staff/{user}/services', [BusinessStaffServiceController::class, 'index'])->whereUlid('business')->whereUlid('user');
        Route::post('{business}/staff/{user}/services', [BusinessStaffServiceController::class, 'store'])->whereUlid('business')->whereUlid('user');
        Route::match(['put', 'patch'], '{business}/staff/{user}/services', [BusinessStaffServiceController::class, 'update'])->whereUlid('business')->whereUlid('user');
        Route::delete('{business}/staff/{user}/services/{service}', [BusinessStaffServiceController::class, 'destroy'])->whereUlid('business')->whereUlid('user')->whereUlid('service');
        Route::get('{business}/staff/{user}/hours', [BusinessStaffHoursController::class, 'show'])->whereUlid('business')->whereUlid('user');
        Route::put('{business}/staff/{user}/hours', [BusinessStaffHoursController::class, 'update'])->whereUlid('business')->whereUlid('user');
        Route::get('{business}/staff/{user}/leaves', [BusinessStaffLeaveController::class, 'index'])->whereUlid('business')->whereUlid('user');
        Route::post('{business}/staff/{user}/leaves', [BusinessStaffLeaveController::class, 'store'])->whereUlid('business')->whereUlid('user');
        Route::patch('{business}/staff/{user}/leaves/{leave}', [BusinessStaffLeaveController::class, 'update'])->whereUlid('business')->whereUlid('user')->whereUlid('leave');
        Route::delete('{business}/staff/{user}/leaves/{leave}', [BusinessStaffLeaveController::class, 'destroy'])->whereUlid('business')->whereUlid('user')->whereUlid('leave');
    });

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
