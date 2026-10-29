<?php

namespace App\Http\Controllers\Api\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\UpsertRosterStaffRequest;
use App\Http\Resources\BusinessStaffResource;
use App\Models\Business;
use App\Models\BusinessStaff;
use App\Services\BusinessManagement;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BusinessRosterController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BusinessManagement $management) {}

    public function index(Request $request, Business $business): JsonResponse
    {
        $this->management->authorize($request, $business);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100'], 'search' => ['sometimes', 'nullable', 'string', 'max:120']]);
        $query = BusinessStaff::where('business_id', $business->id);
        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereRaw('LOWER(job_title) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhere('mobile_number', 'like', '%'.$search.'%'));
        }

        return $this->paginated(BusinessStaffResource::collection($query->orderBy('name')->orderBy('id')->paginate($request->integer('per_page', 20))));
    }

    public function show(Request $request, Business $business, BusinessStaff $staff): JsonResponse
    {
        $this->assertRecord($request, $business, $staff);

        return $this->resource(new BusinessStaffResource($staff));
    }

    public function store(UpsertRosterStaffRequest $request, Business $business): JsonResponse
    {
        return $this->save($request, $business);
    }

    public function update(UpsertRosterStaffRequest $request, Business $business, BusinessStaff $staff): JsonResponse
    {
        $this->assertRecord($request, $business, $staff);

        return $this->save($request, $business, $staff);
    }

    private function save(UpsertRosterStaffRequest $request, Business $business, ?BusinessStaff $staff = null): JsonResponse
    {
        $data = $request->validated();
        // dd($data);

        if (isset($data['remove_avatar'])) {
            $data['remove_avatar'] = (bool) $data['remove_avatar'];
        }

        return $this->management->mutate($request, $business, $staff ? 'roster.update.'.$staff->id : 'roster.create.'.$business->id, $data, function () use ($request, $business, $staff, $data): array {
            $record = $staff ? BusinessStaff::whereKey($staff->id)->where('business_id', $business->id)->lockForUpdate()->firstOrFail() : new BusinessStaff(['business_id' => $business->id]);
            $phone = $data['mobile_number'] ?? $record->mobile_number;
            $duplicate = BusinessStaff::where('business_id', $business->id)->where('mobile_number', $phone);
            if ($record->exists) {
                $duplicate->whereKeyNot($record->id);
            }
            if ($duplicate->exists()) {
                throw ValidationException::withMessages(['mobile_number' => 'This contact number is already on your active staff roster.']);
            }
            if (isset($data['avatar']) && ($data['remove_avatar'] ?? false)) {
                throw ValidationException::withMessages(['avatar' => 'Choose a replacement photo or remove the photo, not both.']);
            }
            $record->fill(collect($data)->only(['name', 'job_title', 'mobile_number'])->all());
            if (isset($data['avatar']) || ($data['remove_avatar'] ?? false)) {
                $this->management->removeAfterCommit('local', $record->avatar_path);
                $record->avatar_path = isset($data['avatar']) ? $this->management->upload($data['avatar'], 'local', 'staff-portraits/'.$business->id) : null;
            }
            $record->save();

            return [$staff ? 200 : 201, (new BusinessStaffResource($record))->resolve($request)];
        });
    }

    public function destroy(Request $request, Business $business, BusinessStaff $staff): JsonResponse
    {
        $this->assertRecord($request, $business, $staff);

        return $this->management->mutate($request, $business, 'roster.delete.'.$staff->id, [], function () use ($staff): array {
            $staff->refresh()->delete();

            return [200, null];
        }, false);
    }

    public function avatar(Request $request, Business $business, BusinessStaff $staff): StreamedResponse
    {
        $this->assertRecord($request, $business, $staff);
        abort_unless($staff->avatar_path && Storage::disk('local')->exists($staff->avatar_path), 404);

        return Storage::disk('local')->response($staff->avatar_path, 'staff-photo.'.pathinfo($staff->avatar_path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function assertRecord(Request $request, Business $business, BusinessStaff $staff): void
    {
        $this->management->authorize($request, $business);
        abort_unless($staff->business_id === $business->id, 404);
    }
}
