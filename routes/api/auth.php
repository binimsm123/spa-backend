<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:auth')->group(function (): void {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/register/verify', [AuthController::class, 'verifyRegistration']);
    Route::post('auth/verification/resend', [AuthController::class, 'resendVerification']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/refresh', [AuthController::class, 'refresh']);
    Route::post('auth/password/forgot', [AuthController::class, 'passwordForgot']);
    Route::post('auth/password/verify', [AuthController::class, 'passwordVerify']);
    Route::post('auth/password/resend', [AuthController::class, 'passwordResend']);
    Route::post('auth/password/reset', [AuthController::class, 'passwordReset']);
});

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::post('auth/password/change', [AuthController::class, 'passwordChange']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
});
