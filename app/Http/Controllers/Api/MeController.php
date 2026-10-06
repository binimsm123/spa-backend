<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Dto\Api\LocationUpdateData;
use App\Http\Dto\Api\LocationsData;
use App\Http\Dto\Api\ProfileData;
use App\Http\Requests\Profile\UpdateLocationRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\LocationResource;
use App\Http\Resources\UserResource;
use App\Models\Location;
use App\Models\UserLocation;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function locations(Request $request): JsonResponse
    {
        $locations = Location::query()
            ->where('is_serviceable', true)
            ->orderBy('city')
            ->orderBy('name')
            ->get(['id', 'region', 'name', 'city', 'latitude', 'longitude', 'is_serviceable']);

        return $this->success((new LocationsData($request->user(), $locations, $request->query('region')))->toArray(), 'Locations loaded.');
    }

    public function updateLocation(UpdateLocationRequest $request): JsonResponse
    {
        $user = $request->user();
        $locationId = $request->validated('location_id');

        DB::transaction(function () use ($user, $locationId): void {
            $user->forceFill(['selected_location_id' => $locationId])->save();

            UserLocation::query()->updateOrCreate(
                ['user_id' => $user->getKey(), 'location_id' => $locationId],
                ['is_current' => true, 'selected_at' => now()],
            );

            UserLocation::query()
                ->where('user_id', $user->getKey())
                ->where('location_id', '!=', $locationId)
                ->update(['is_current' => false]);
        });

        $location = Location::query()->findOrFail($locationId);

        return $this->success((new LocationUpdateData($user->fresh(), $location))->toArray(), 'Location updated.');
    }
}
