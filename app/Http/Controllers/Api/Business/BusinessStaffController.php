<?php

namespace App\Http\Controllers\Api\Business;

use App\Enums\BusinessUserRole;
use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\InviteStaffRequest;
use App\Http\Requests\Business\UpdateStaffRequest;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class BusinessStaffController extends Controller
{
    use ApiResponse;

    public function index(Request $request, Business $business): JsonResponse
    {
        $this->assertMember($request, $business);

        $data = $request->validate([
            'status' => ['sometimes', 'required', 'in:active,inactive'],
            'role' => ['sometimes', 'required', 'in:owner,manager,staff'],
        ]);

        $query = $business->members();

        if (isset($data['status'])) {
            $query->wherePivot('is_active', $data['status'] === 'active');
        }

        if (isset($data['role'])) {
            $query->wherePivot('role', $data['role']);
        }

        $members = $query
            ->withPivot('role', 'is_active')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'display_name' => $u->display_name,
                'job_title' => $u->job_title,
                'image' => $u->image ? url('storage/'.$u->image) : null,
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

        $imagePath = null;

        try {
            $staff = DB::transaction(function () use ($data, $business, &$imagePath): User {
                $user = User::query()->where('mobile', $data['mobile_number'])->first();

                if ($user === null) {
                    if (isset($data['image'])) {
                        $imagePath = $data['image']->store('avatars', 'public');

                        if ($imagePath === false) {
                            throw new RuntimeException('Unable to store the uploaded image. Please retry.');
                        }
                    }

                    $user = User::query()->create([
                        'mobile' => $data['mobile_number'],
                        'display_name' => $data['name'] ?? 'Staff member',
                        'job_title' => $data['job_title'] ?? null,
                        'image' => $imagePath,
                        'password' => Hash::make($data['password'] ?? 'Staff@123'),
                    ]);
                    $user->assignRole('staff');
                }

                BusinessUser::query()->updateOrCreate(
                    ['business_id' => $business->getKey(), 'user_id' => $user->getKey()],
                    ['role' => $data['role'], 'is_active' => true],
                );

                return $user;
            });
        } catch (Throwable $exception) {
            if (is_string($imagePath)) {
                Storage::disk('public')->delete($imagePath);
            }

            throw $exception;
        }

        app(NotificationService::class)->notify(
            $staff,
            NotificationType::StaffInvite,
            'You have been added to '.$business->name,
            'You were invited as '.$data['role'].'.',
            ['business_id' => $business->getKey()],
        );

        return $this->success([
            'id' => $staff->getKey(),
            'display_name' => $staff->display_name,
            'job_title' => $staff->job_title,
            'image' => $staff->image ? url('storage/'.$staff->image) : null,
            'role' => $data['role'],
            'mobile_number' => $staff->mobile,
        ], 'Staff member added.', 201);
    }

    public function update(UpdateStaffRequest $request, Business $business, User $user): JsonResponse
    {
        $this->assertMember($request, $business, ['owner', 'manager']);

        $data = $request->validated();
        $imagePath = null;

        try {
            [$staff, $membership, $oldImage] = DB::transaction(function () use ($data, $business, $user, &$imagePath): array {
                $staff = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                $membership = BusinessUser::query()
                    ->where('business_id', $business->getKey())
                    ->where('user_id', $staff->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $oldImage = $staff->image;

                if (isset($data['name'])) {
                    $staff->display_name = $data['name'];
                }

                if (array_key_exists('job_title', $data)) {
                    $staff->job_title = $data['job_title'];
                }

                if (array_key_exists('image', $data)) {
                    if ($data['image'] !== null) {
                        $imagePath = $data['image']->store('avatars', 'public');

                        if ($imagePath === false) {
                            throw new RuntimeException('Unable to store the uploaded image. Please retry.');
                        }
                    }

                    $staff->image = $imagePath;
                }

                $staff->save();

                if (isset($data['role'])) {
                    $membership->role = $data['role'];
                    $membership->save();
                }

                return [$staff, $membership, $oldImage];
            });
        } catch (Throwable $exception) {
            if (is_string($imagePath)) {
                Storage::disk('public')->delete($imagePath);
            }

            throw $exception;
        }

        if ($oldImage && $oldImage !== $staff->image) {
            Storage::disk('public')->delete($oldImage);
        }

        return $this->success([
            'id' => $staff->getKey(),
            'display_name' => $staff->display_name,
            'job_title' => $staff->job_title,
            'image' => $staff->image ? url('storage/'.$staff->image) : null,
            'role' => $membership->role,
            'mobile_number' => $staff->mobile,
            'is_active' => (bool) $membership->is_active,
        ], 'Staff member updated.');
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
            fn (string $r) => BusinessUserRole::from($r),
            $roles,
        ))) {
            abort(403, 'Your role cannot perform this action.');
        }
    }
}
