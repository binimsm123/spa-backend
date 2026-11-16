<?php

namespace App\Http\Controllers\Api\Business;

use App\Enums\BusinessUserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Business;
use App\Services\BookingStaffService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessBookingStaffController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BookingStaffService $staffing) {}

    public function suggestions(Request $request, Business $business, Booking $booking): JsonResponse
    {
        $this->authorizeManager($request, $business, $booking);

        return $this->success($this->staffing->suggestions($booking), 'Staff suggestions loaded.');
    }

    public function update(Request $request, Business $business, Booking $booking): JsonResponse
    {
        $this->authorizeManager($request, $business, $booking);
        $data = $request->validate([
            'staff_user_ids' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'staff_user_ids.*' => ['required', 'ulid', 'distinct'],
        ]);
        $booking = $this->staffing->assign($booking, $data['staff_user_ids']);

        return $this->resource(BookingResource::make($booking), 'Booking staff assigned.');
    }

    private function authorizeManager(Request $request, Business $business, Booking $booking): void
    {
        abort_unless($request->user()->hasBusinessRole($business, BusinessUserRole::Owner, BusinessUserRole::Manager), 403, 'Only owners and managers can assign booking staff.');
        abort_unless($booking->business_id === $business->id, 404);
    }
}
