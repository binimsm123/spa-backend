<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Dto\Api\ProfileData;
use App\Http\Requests\Profile\UpdateLocationRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\Business;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function me(Request $request): JsonResponse
    {
        return $this->success((new ProfileData($request->user()))->toArray(), 'Profile loaded.');
    }

    public function locations(): JsonResponse
    {
        $cities = Business::query()
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->orderBy('city')
            ->pluck('city');

        return $this->success($cities, 'Cities loaded.');
    }

    public function updateLocation(UpdateLocationRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->fill($request->validated())->save();

        return $this->success((new ProfileData($user->refresh()))->toArray(), 'Address updated.');
    }

    public function updateMe(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('avatars', 'public');
            $data['image'] = $path;
        }

        if ($data === [] && ! $request->hasFile('avatar')) {
            return $this->resource(UserResource::make($user), 'Nothing to update.');
        }

        // Changing the mobile number starts a new verification flow.
        if (isset($data['mobile']) && $data['mobile'] !== $user->mobile) {
            $user->forceFill(['mobile_verified_at' => null])->save();
        }

        unset($data['mobile']);

        $user->fill($data)->save();

        return $this->success((new ProfileData($user->refresh()))->toArray(), 'Profile updated.');
    }
}
