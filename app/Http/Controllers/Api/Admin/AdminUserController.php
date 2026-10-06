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
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $query = User::query()
            ->with('roles')
            ->when($request->query('q'), function ($q, $search): void {
                $like = '%'.strtolower($search).'%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(display_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(mobile) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like]));
            })
            ->when($request->query('role'), fn ($q, $role) => $q->whereHas('roles', fn ($r) => $r->where('name', $role)))
            ->when($request->query('status') === 'disabled', fn ($q) => $q->where('is_active', false))
            ->when($request->query('status') === 'enabled', fn ($q) => $q->where('is_active', true))
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
