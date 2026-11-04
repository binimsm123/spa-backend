<?php

namespace App\Http\Controllers\Api\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\StaffLeaveRequest;
use App\Http\Resources\StaffLeaveResource;
use App\Models\Business;
use App\Models\StaffLeave;
use App\Models\User;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BusinessStaffLeaveController extends Controller
{
    use ApiResponse;

    public function index(StaffLeaveRequest $request, Business $business, User $user): JsonResponse
    {
        $data = $request->validated();
        $query = $request->staffMembership()->leaves()->orderBy('starts_on')->orderBy('starts_at')->orderBy('id');

        if (isset($data['from'])) {
            $query->where('ends_on', '>=', $data['from']);
        }

        if (isset($data['to'])) {
            $query->where('starts_on', '<=', $data['to']);
        }

        if ($request->hasAny(['page', 'per_page'])) {
            return $this->paginated(StaffLeaveResource::collection($query->paginate($request->integer('per_page', 20))));
        }

        return $this->success(['items' => StaffLeaveResource::collection($query->get())->resolve($request)]);
    }

    public function store(StaffLeaveRequest $request, Business $business, User $user): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $leaves = $request->staffMembership(true)->leaves()->createMany($request->validated('leaves'));

            return $this->created(['items' => StaffLeaveResource::collection($leaves)->resolve($request)], 'Staff leaves added.');
        });
    }

    public function update(StaffLeaveRequest $request, Business $business, User $user, StaffLeave $leave): JsonResponse
    {
        return DB::transaction(function () use ($request, $leave): JsonResponse {
            $record = $request->staffMembership(true)->leaves()->whereKey($leave->getKey())->lockForUpdate()->first()
                ?? throw ApiException::notFound('Staff leave not found in this business.');
            $record->fill($request->safe()->only(['starts_on', 'ends_on', 'starts_at', 'ends_at', 'reason']))->save();

            return $this->resource(new StaffLeaveResource($record), 'Staff leave updated.');
        });
    }

    public function destroy(StaffLeaveRequest $request, Business $business, User $user, StaffLeave $leave): JsonResponse
    {
        DB::transaction(function () use ($request, $leave): void {
            $record = $request->staffMembership(true)->leaves()->whereKey($leave->getKey())->lockForUpdate()->first()
                ?? throw ApiException::notFound('Staff leave not found in this business.');
            $record->delete();
        });

        return $this->success(null, 'Staff leave removed.');
    }
}
