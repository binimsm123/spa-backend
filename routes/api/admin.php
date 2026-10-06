<?php

use App\Http\Controllers\Api\Admin\AdminAuditController;
use App\Http\Controllers\Api\Admin\AdminBookingController;
use App\Http\Controllers\Api\Admin\AdminBusinessController;
use App\Http\Controllers\Api\Admin\AdminCatalogController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminNotificationController;
use App\Http\Controllers\Api\Admin\AdminOfferController;
use App\Http\Controllers\Api\Admin\AdminPaymentController;
use App\Http\Controllers\Api\Admin\AdminReportController;
use App\Http\Controllers\Api\Admin\AdminReviewController;
use App\Http\Controllers\Api\Admin\AdminRewardController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:admin', 'permission:admin.dashboard.view'])->prefix('admin')->group(function (): void {
    Route::get('dashboard', [AdminDashboardController::class, 'overview']);

    // Users
    Route::middleware('permission:admin.users.manage')->group(function (): void {
        Route::get('users', [AdminUserController::class, 'index']);
        Route::get('users/{user}', [AdminUserController::class, 'show'])->whereUlid('user');
        Route::patch('users/{user}/status', [AdminUserController::class, 'setEnabled'])->whereUlid('user');
        Route::patch('users/{user}/roles', [AdminUserController::class, 'setRoles'])->whereUlid('user');
    });

    // Businesses
    Route::middleware('permission:admin.businesses.manage')->group(function (): void {
        Route::get('businesses', [AdminBusinessController::class, 'index']);
        Route::get('businesses/{business}', [AdminBusinessController::class, 'show'])->whereUlid('business');
        Route::patch('businesses/{business}/state', [AdminBusinessController::class, 'changeState'])->whereUlid('business');
    });

    // Catalog
    Route::middleware('permission:admin.catalog.manage')->group(function (): void {
        Route::get('categories', [AdminCatalogController::class, 'categories']);
        Route::post('categories', [AdminCatalogController::class, 'storeCategory']);
        Route::patch('categories/{category}', [AdminCatalogController::class, 'updateCategory'])->whereUlid('category');
        Route::get('services', [AdminCatalogController::class, 'services']);
        Route::patch('services/{service}', [AdminCatalogController::class, 'updateService'])->whereUlid('service');
    });

    // Bookings
    Route::middleware('permission:admin.bookings.manage')->group(function (): void {
        Route::get('bookings', [AdminBookingController::class, 'index']);
        Route::get('bookings/{booking}', [AdminBookingController::class, 'show'])->whereUlid('booking');
        Route::patch('bookings/{booking}/status', [AdminBookingController::class, 'updateStatus'])->whereUlid('booking');
        Route::patch('bookings/{booking}/staff', [AdminBookingController::class, 'reassignStaff'])->whereUlid('booking');
    });

    // Payments
    Route::middleware('permission:admin.payments.manage')->group(function (): void {
        Route::get('payments', [AdminPaymentController::class, 'index']);
        Route::get('payments/{payment}', [AdminPaymentController::class, 'show'])->whereUlid('payment');
        Route::post('payments/{payment}/refund', [AdminPaymentController::class, 'refund'])->whereUlid('payment');
    });

    // Offers
    Route::middleware('permission:admin.offers.manage')->group(function (): void {
        Route::get('offers', [AdminOfferController::class, 'index']);
        Route::post('offers', [AdminOfferController::class, 'store']);
        Route::patch('offers/{offer}', [AdminOfferController::class, 'update'])->whereUlid('offer');
        Route::post('offers/{offer}/expire', [AdminOfferController::class, 'expire'])->whereUlid('offer');
    });

    // Rewards
    Route::middleware('permission:admin.rewards.manage')->group(function (): void {
        Route::get('rewards/configurations', [AdminRewardController::class, 'configurations']);
        Route::post('rewards/configurations', [AdminRewardController::class, 'storeConfiguration']);
        Route::get('rewards/transactions', [AdminRewardController::class, 'transactions']);
        Route::post('users/{user}/rewards/adjust', [AdminRewardController::class, 'adjust'])->whereUlid('user');
    });

    // Reviews
    Route::middleware('permission:admin.reviews.moderate')->group(function (): void {
        Route::get('reviews', [AdminReviewController::class, 'index']);
        Route::get('reviews/{review}', [AdminReviewController::class, 'show'])->whereUlid('review');
        Route::post('reviews/{review}/hide', [AdminReviewController::class, 'hide'])->whereUlid('review');
        Route::post('reviews/{review}/restore', [AdminReviewController::class, 'restore'])->whereUlid('review');
    });

    // Notifications
    Route::middleware('permission:admin.notifications.manage')->group(function (): void {
        Route::get('notifications', [AdminNotificationController::class, 'index']);
        Route::patch('notifications/{notification}/read', [AdminNotificationController::class, 'markRead'])->whereUlid('notification');
        Route::post('announcements', [AdminNotificationController::class, 'announce']);
    });

    // Reports
    Route::middleware('permission:admin.reports.view')->prefix('reports')->group(function (): void {
        Route::get('bookings', [AdminReportController::class, 'bookings']);
        Route::get('payments', [AdminReportController::class, 'payments']);
        Route::get('popular-services', [AdminReportController::class, 'popularServices']);
        Route::get('business-performance', [AdminReportController::class, 'businessPerformance']);
        Route::get('reviews', [AdminReportController::class, 'reviews']);
        Route::get('offers', [AdminReportController::class, 'offers']);
    });

    // Audit log
    Route::middleware('permission:admin.audit.view')->get('audit-logs', [AdminAuditController::class, 'index']);
});
