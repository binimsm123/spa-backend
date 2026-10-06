<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BusinessStateRequest;
use App\Http\Resources\BusinessResource;
use App\Models\Business;
use App\Support\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminBusinessController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $query = Business::query()
            ->with('locations')
            ->when($request->query('q'), function ($q, $search): void {
                $like = '%'.strtolower($search).'%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(slug) LIKE ?', [$like])
                    ->orWhereHas('locations', fn ($l) => $l
                        ->whereRaw('LOWER(address) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(city) LIKE ?', [$like])));
            })
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('verification') === 'verified', fn ($q) => $q->where('is_verified', true))
            ->when($request->query('verification') === 'unverified', fn ($q) => $q->where('is_verified', false))
            ->when($request->query('online') !== null && $request->has('online'), fn ($q) => $q->where('is_online', $request->boolean('online')))
            ->orderByDesc('created_at');

        return $this->paginated(BusinessResource::collection($query->paginate($perPage)));
    }

    public function show(Request $request, Business $business): JsonResponse
    {
        $business->load(['locations.hours', 'locations.closures', 'services.category', 'members']);

        $data = BusinessResource::make($business)->resolve();
        $data['members'] = $business->members->map(fn ($u) => [
            'id' => $u->id,
            'display_name' => $u->display_name,
            'mobile_number' => $u->mobile,
            'role' => $u->pivot->role ?? null,
            'is_active' => (bool) ($u->pivot->is_active ?? false),
        ]);

        return $this->success($data);
    }

    public function changeState(BusinessStateRequest $request, Business $business): JsonResponse
    {
        $data = $request->validated();
        $before = $business->only(['status', 'is_verified', 'is_online']);

        match ($data['action']) {
            'verify' => $business->forceFill(['is_verified' => true, 'status' => 'active'])->save(),
            'reject' => $business->forceFill(['is_verified' => false, 'status' => 'pending'])->save(),
            'suspend' => $business->forceFill(['status' => 'suspended', 'is_online' => false])->save(),
            'reactivate' => $business->forceFill(['status' => 'active'])->save(),
            'online' => $business->forceFill(['is_online' => true])->save(),
            'offline' => $business->forceFill(['is_online' => false])->save(),
        };

        AuditLogger::record(
            $request->user(),
            'business.'.$data['action'],
            'business',
            $business->getKey(),
            $before,
            $business->only(['status', 'is_verified', 'is_online']),
            $data['reason'] ?? null,
        );

        return $this->resource(BusinessResource::make($business->refresh()), 'Business updated.');
    }
}
