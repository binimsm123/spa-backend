<?php

namespace App\Http\Controllers\Api\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\StaffHoursRequest;
use App\Http\Resources\StaffHourResource;
use App\Models\Business;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BusinessStaffHoursController extends Controller
{
    use ApiResponse;

    public function show(StaffHoursRequest $request, Business $business, User $user): JsonResponse
    {
        return $this->success(['hours' => StaffHourResource::collection($request->staffMembership()->hours()->orderBy('weekday')->get())->resolve($request)]);
    }

    public function update(StaffHoursRequest $request, Business $business, User $user): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $membership = $request->staffMembership(true);

            foreach ($request->validated('hours') as $row) {
                $membership->hours()->updateOrCreate(['weekday' => $row['weekday']], [
                    'opens_at' => $row['is_closed'] ? null : $row['opens_at'],
                    'closes_at' => $row['is_closed'] ? null : $row['closes_at'],
                    'is_closed' => $row['is_closed'],
                ]);
            }

            return $this->success([
                'hours' => StaffHourResource::collection($membership->hours()->orderBy('weekday')->get())->resolve($request),
            ], 'Staff working hours updated.');
        });
    }
}
