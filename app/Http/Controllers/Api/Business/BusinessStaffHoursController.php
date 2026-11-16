<?php

namespace App\Http\Controllers\Api\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\StaffHoursRequest;
use App\Http\Resources\StaffHourResource;
use App\Models\Business;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\StaffBreaks;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

            foreach ($request->validated('hours') as $index => $row) {
                $hours = $membership->hours()->firstOrNew(['weekday' => $row['weekday']]);
                $breaks = $row['is_closed'] ? [] : StaffBreaks::normalize($row['breaks'] ?? $hours->breaks ?? []);
                if (! $row['is_closed']) {
                    $errors = StaffBreaks::errors($breaks, $row['opens_at'], $row['closes_at'], 'hours.'.$index.'.breaks');
                    if ($errors !== []) {
                        throw ValidationException::withMessages($errors);
                    }
                }
                $hours->fill([
                    'opens_at' => $row['is_closed'] ? null : $row['opens_at'],
                    'closes_at' => $row['is_closed'] ? null : $row['closes_at'],
                    'is_closed' => $row['is_closed'],
                    'breaks' => $breaks,
                ])->save();
            }

            return $this->success([
                'hours' => StaffHourResource::collection($membership->hours()->orderBy('weekday')->get())->resolve($request),
            ], 'Staff working hours updated.');
        });
    }
}
