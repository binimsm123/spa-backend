<?php

namespace App\Http\Controllers\Api\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\StaffBreakRequest;
use App\Http\Resources\StaffHourResource;
use App\Models\Business;
use App\Models\User;
use App\Support\ApiException;
use App\Support\ApiResponse;
use App\Support\StaffBreaks;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessStaffBreakController extends Controller
{
    use ApiResponse;

    public function index(StaffBreakRequest $request, Business $business, User $user): JsonResponse
    {
        $hours = $request->staffMembership()->hours()->where('weekday', $request->validated('weekday'))->first()
            ?? throw ApiException::notFound('Staff working hours not found for this weekday.');

        return $this->resource(new StaffHourResource($hours), 'Staff breaks loaded.');
    }

    public function store(StaffBreakRequest $request, Business $business, User $user): JsonResponse
    {
        return $this->save($request, true);
    }

    public function update(StaffBreakRequest $request, Business $business, User $user): JsonResponse
    {
        return $this->save($request, false);
    }

    private function save(StaffBreakRequest $request, bool $append): JsonResponse
    {
        return DB::transaction(function () use ($request, $append): JsonResponse {
            $hours = $request->staffMembership(true)->hours()
                ->where('weekday', $request->validated('weekday'))->lockForUpdate()->first()
                ?? throw ApiException::notFound('Staff working hours not found for this weekday.');
            $breaks = StaffBreaks::normalize([
                ...($append ? ($hours->breaks ?? []) : []),
                ...$request->validated('breaks'),
            ]);
            if ($breaks !== []) {
                if ($hours->is_closed || $hours->opens_at === null || $hours->closes_at === null) {
                    throw ValidationException::withMessages(['weekday' => 'Breaks can only be added to a configured working day.']);
                }
                $errors = StaffBreaks::errors($breaks, $hours->opens_at->format('H:i'), $hours->closes_at->format('H:i'));
                if ($errors !== []) {
                    throw ValidationException::withMessages(['breaks' => collect($errors)->flatten()->unique()->values()->all()]);
                }
            }
            $hours->forceFill(['breaks' => $breaks])->save();

            return $this->resource(new StaffHourResource($hours), $append ? 'Staff breaks added.' : 'Staff breaks updated.', $append ? 201 : 200);
        });
    }
}
