<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Review;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminReportController extends Controller
{
    use ApiResponse;

    public function bookings(Request $request): JsonResponse
    {
        $from = Carbon::parse($request->query('from', now()->subDays(30)));
        $to = Carbon::parse($request->query('to', now()));
        $groupBy = $request->query('group_by', 'date'); // date | business

        if ($groupBy === 'business') {
            $rows = Booking::query()
                ->join('businesses', 'businesses.id', '=', 'bookings.business_id')
                ->whereBetween('bookings.appointment_date', [$from->toDateString(), $to->toDateString()])
                ->selectRaw('businesses.name as label, COUNT(*) as bookings_count, SUM(bookings.total_minor) as revenue_minor')
                ->groupBy('businesses.name')
                ->orderByDesc('bookings_count')
                ->get();
        } else {
            $rows = Booking::query()
                ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
                ->selectRaw('DATE(appointment_date) as label, COUNT(*) as bookings_count, SUM(total_minor) as revenue_minor')
                ->groupBy('label')
                ->orderBy('label')
                ->get();
        }

        return $this->success(['items' => $rows, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);
    }

    public function payments(Request $request): JsonResponse
    {
        $from = Carbon::parse($request->query('from', now()->subDays(30)));
        $to = Carbon::parse($request->query('to', now()));

        $byStatus = Payment::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, COUNT(*) as count, SUM(amount_minor) as amount_minor')
            ->groupBy('status')
            ->get();

        $byGateway = Payment::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('gateway, COUNT(*) as count, SUM(amount_minor) as amount_minor')
            ->groupBy('gateway')
            ->get();

        return $this->success(['by_status' => $byStatus, 'by_gateway' => $byGateway]);
    }

    public function popularServices(Request $request): JsonResponse
    {
        $from = Carbon::parse($request->query('from', now()->subDays(30)));
        $to = Carbon::parse($request->query('to', now()));

        $rows = BookingItem::query()
            ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
            ->whereBetween('bookings.appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('bookings.status', ['confirmed', 'in_progress', 'completed'])
            ->selectRaw('service_name_snapshot as label, COUNT(*) as bookings_count, SUM(unit_price_minor * quantity) as revenue_minor')
            ->groupBy('service_name_snapshot')
            ->orderByDesc('bookings_count')
            ->limit(50)
            ->get();

        return $this->success(['items' => $rows]);
    }

    public function businessPerformance(Request $request): JsonResponse
    {
        $rows = Business::query()
            ->where('status', 'active')
            ->withCount(['bookings as bookings_count' => fn ($q) => $q->whereIn('status', ['confirmed', 'completed'])])
            ->withSum(['bookings as revenue_minor' => fn ($q) => $q->whereIn('status', ['confirmed', 'completed'])], 'total_minor')
            ->orderByDesc('bookings_count')
            ->limit(100)
            ->get(['id', 'name', 'rating_average', 'rating_count']);

        return $this->success(['items' => $rows->map(fn (Business $b) => [
            'id' => $b->id,
            'name' => $b->name,
            'rating_average' => (float) $b->rating_average,
            'rating_count' => (int) $b->rating_count,
            'bookings_count' => (int) $b->bookings_count,
            'revenue_minor' => (int) ($b->revenue_minor ?? 0),
        ])]);
    }

    public function reviews(Request $request): JsonResponse
    {
        $rows = Review::query()
            ->selectRaw('rating, COUNT(*) as count')
            ->groupBy('rating')
            ->orderBy('rating')
            ->get();

        return $this->success(['items' => $rows]);
    }

    public function offers(Request $request): JsonResponse
    {
        $rows = \App\Models\Offer::query()
            ->withCount('redemptions as redemption_count')
            ->orderByDesc('redemption_count')
            ->limit(100)
            ->get(['id', 'code', 'title', 'status', 'usage_count', 'total_usage_limit']);

        return $this->success(['items' => $rows]);
    }
}
