<?php

namespace App\Http\Controllers\Api\Business;

use App\Enums\BusinessUserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\InviteStaffRequest;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BusinessStaffController extends Controller
{
    use ApiResponse;

    public function index(Request $request, Business $business): JsonResponse
    {
        $this->assertMember($request, $business);

        $members = $business->members()
            ->withPivot('role', 'is_active')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'display_name' => $u->display_name,
                'mobile_number' => $u->mobile,
                'role' => $u->pivot->role,
                'is_active' => (bool) $u->pivot->is_active,
            ]);

        return $this->success(['items' => $members]);
    }

    public function invite(InviteStaffRequest $request, Business $business): JsonResponse
    {
        $this->assertMember($request, $business, ['owner', 'manager']);

        $data = $request->validated();

        $staff = DB::transaction(function () use ($data, $business): User {
            $user = User::query()->where('mobile', $data['mobile_number'])->first();

            if ($user === null) {
                $user = User::query()->create([
                    'mobile' => $data['mobile_number'],
                    'display_name' => $data['display_name'] ?? 'Staff member',
                    'password' => Hash::make(Str::random(24)),
                ]);
                $user->assignRole('staff');
            }

            BusinessUser::query()->updateOrCreate(
                ['business_id' => $business->getKey(), 'user_id' => $user->getKey()],
                ['role' => $data['role'], 'is_active' => true],
            );

            return $user;
        });

        app(\App\Services\NotificationService::class)->notify(
            $staff,
            \App\Enums\NotificationType::StaffInvite,
            'You have been added to '.$business->name,
            'You were invited as '.$data['role'].'.',
            ['business_id' => $business->getKey()],
        );

        return $this->success([
            'id' => $staff->getKey(),
            'display_name' => $staff->display_name,
            'role' => $data['role'],
        ], 'Staff member added.', 201);
    }

    public function deactivate(Request $request, Business $business, User $user): JsonResponse
    {
        $this->assertMember($request, $business, ['owner', 'manager']);

        BusinessUser::query()
            ->where('business_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->update(['is_active' => false]);

        return $this->success(null, 'Staff member deactivated.');
    }

    private function assertMember(Request $request, Business $business, ?array $roles = null): void
    {
        $user = $request->user();

        abort_unless($user->belongsToBusiness($business), 403, 'You do not manage this business.');

        if ($roles !== null && ! $user->hasBusinessRole($business, ...array_map(
            fn (string $r) => \App\Enums\BusinessUserRole::from($r),
            $roles,
        ))) {
            abort(403, 'Your role cannot perform this action.');
        }
    }
}
