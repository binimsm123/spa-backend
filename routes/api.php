<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    require base_path('routes/api/auth.php');
    require base_path('routes/api/customer.php');
    require base_path('routes/api/business.php');
    require base_path('routes/api/admin.php');
    require base_path('routes/api/webhooks.php');
});
