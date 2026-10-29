<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleAssignmentRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        return $this->userList($request);
    }

    public function admins(Request $request): JsonResponse
    {
        return $this->userList($request, 'admins');
    }

    public function customers(Request $request): JsonResponse
    {
        return $this->userList($request, 'customer');
    }

    private function userList(Request $request, ?string $group = null): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:120'],
            'role' => ['sometimes', 'string', 'in:superadmin,owner,manager,staff,customer'],
            'status' => ['sometimes', 'string', 'in:enabled,disabled'],
            'created_from' => ['sometimes', 'date_format:Y-m-d'],
            'created_to' => ['sometimes', 'date_format:Y-m-d'],
            'signup_from' => ['sometimes', 'string', 'in:web,android,ios'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $perPage = (int) ($filters['per_page'] ?? 20);

        $query = User::query()->with('roles')
            ->when($group === 'customer', fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', 'customer')))
            ->when($group === 'admins', fn ($q) => $q->whereHas('roles', fn ($r) => $r->whereIn('name', ['superadmin', 'owner', 'manager', 'staff'])))
            ->when(isset($filters['q']), function ($q) use ($filters): void {
                $like = '%'.strtolower($filters['q']).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(display_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(mobile) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like]));
            })
            ->when(isset($filters['role']), fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', $filters['role'])))
            ->when(($filters['status'] ?? null) === 'disabled', fn ($q) => $q->where('is_active', false))
            ->when(($filters['status'] ?? null) === 'enabled', fn ($q) => $q->where('is_active', true))
            ->when(isset($filters['created_from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['created_from']))
            ->when(isset($filters['created_to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['created_to']))
            ->when(isset($filters['signup_from']), fn ($q) => $q->where('signup_from', $filters['signup_from']))
            ->orderByDesc('created_at');

        return $this->paginated(UserResource::collection($query->paginate($perPage)));
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $user->load(['roles', 'businessMemberships.business']);

        $data = UserResource::make($user)->resolve();
        $data['business_memberships'] = $user->businessMemberships->map(fn ($m) => [
            'business_id' => $m->business_id,
            'business_name' => $m->business?->name,
            'role' => $m->role,
            'is_active' => (bool) $m->is_active,
        ]);
        $data['bookings_count'] = $user->bookings()->count();
        $data['reviews_count'] = $user->reviews()->count();

        return $this->success($data);
    }

    public function setEnabled(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            'reason' => ['required_if:is_active,false', 'nullable', 'string', 'max:500'],
        ]);

        $before = $user->only('is_active');
        $user->forceFill(['is_active' => (bool) $data['is_active']])->save();

        AuditLogger::record(
            $request->user(),
            'user.'.($data['is_active'] ? 'enabled' : 'disabled'),
            'user',
            $user->getKey(),
            $before,
            ['is_active' => $user->is_active],
            $data['reason'] ?? null,
        );

        return $this->resource(UserResource::make($user->refresh()), 'Account updated.');
    }

    public function setRoles(RoleAssignmentRequest $request, User $user): JsonResponse
    {
        $before = $user->getRoleNames()->toArray();

        $user->syncRoles($request->validated('roles'));

        AuditLogger::record(
            $request->user(),
            'user.roles_changed',
            'user',
            $user->getKey(),
            ['roles' => $before],
            ['roles' => $user->getRoleNames()->toArray()],
        );

        return $this->resource(UserResource::make($user->refresh()), 'Roles updated.');
    }
}
