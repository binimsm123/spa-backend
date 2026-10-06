<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    use ApiResponse;

    public function overview(): JsonResponse
    {
        $businesses = \App\Models\Business::query()->withTrashed()->get(['id', 'status', 'is_online', 'is_verified']);
        $bookings = \App\Models\Booking::query()->get(['id', 'status']);
        $customers = \App\Models\User::role('customer')->count();

        return $this->success([
            'businesses' => [
                'total_active' => $businesses->where('status', 'active')->count(),
                'online' => $businesses->where('is_online', true)->count(),
                'offline' => $businesses->where('is_online', false)->count(),
                'pending_verification' => $businesses->where('is_verified', false)->where('status', 'pending')->count(),
            ],
            'customers' => $customers,
            'bookings' => [
                'upcoming' => $bookings->whereIn('status', ['awaiting_payment', 'confirmed', 'in_progress'])->count(),
                'completed' => $bookings->where('status', 'completed')->count(),
                'cancelled' => $bookings->where('status', 'cancelled')->count(),
            ],
            'payments' => [
                'succeeded_total_minor' => (int) \App\Models\Payment::query()->where('status', 'succeeded')->sum('amount_minor'),
                'failed' => (int) \App\Models\Payment::query()->where('status', 'failed')->count(),
            ],
            'offers' => ['active' => \App\Models\Offer::query()->where('status', 'active')->count()],
            'rewards' => [
                'issued' => (int) \App\Models\RewardPointTransaction::query()->where('points', '>', 0)->sum('points'),
                'redeemed' => abs((int) \App\Models\RewardPointTransaction::query()->where('points', '<', 0)->sum('points')),
            ],
            'unread_notifications' => \App\Models\Notification::query()->whereNull('read_at')->count(),
        ]);
    }
}
