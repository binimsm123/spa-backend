<?php

namespace App\Http\Controllers\Api\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\StaffServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Service;
use App\Models\User;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessStaffServiceController extends Controller
{
    use ApiResponse;

    public function index(StaffServiceRequest $request, Business $business, User $user): JsonResponse
    {
        $membership = $this->membership($business, $user);
        $services = $this->services($membership);

        if ($request->hasAny(['page', 'per_page'])) {
            return $this->paginated(ServiceResource::collection($services->paginate($request->integer('per_page', 20))));
        }

        return $this->success(['items' => ServiceResource::collection($services->get())->resolve($request)]);
    }

    public function store(StaffServiceRequest $request, Business $business, User $user): JsonResponse
    {
        return $this->save($request, $business, $user, false);
    }

    public function update(StaffServiceRequest $request, Business $business, User $user): JsonResponse
    {
        return $this->save($request, $business, $user, true);
    }

    public function destroy(StaffServiceRequest $request, Business $business, User $user, Service $service): JsonResponse
    {
        if ($service->business_id !== $business->getKey()) {
            throw ApiException::notFound('Service not found in this business.');
        }

        DB::transaction(function () use ($business, $user, $service): void {
            $membership = $this->membership($business, $user, true);
            if (! $membership->services()->whereKey($service->getKey())->exists()) {
                throw ApiException::notFound('Service expertise is not assigned to this staff member.');
            }
            $membership->services()->detach($service->getKey());
        });

        return $this->success(null, 'Staff service expertise removed.');
    }

    private function save(StaffServiceRequest $request, Business $business, User $user, bool $replace): JsonResponse
    {
        $data = $request->validated();

        return DB::transaction(function () use ($request, $business, $user, $data, $replace): JsonResponse {
            $membership = $this->membership($business, $user, true);
            $serviceIds = $business->services()->whereKey($data['service_ids'])->lockForUpdate()->pluck('id')->all();

            if (count($serviceIds) !== count($data['service_ids'])) {
                throw ValidationException::withMessages(['service_ids' => 'One or more selected services are no longer available.']);
            }

            if ($replace) {
                $membership->services()->sync($serviceIds);
            } else {
                $membership->services()->syncWithoutDetaching($serviceIds);
            }

            return $this->success(
                ['items' => ServiceResource::collection($this->services($membership)->get())->resolve($request)],
                $replace ? 'Staff service expertise updated.' : 'Staff service expertise added.',
                $replace ? 200 : 201,
            );
        });
    }

    private function membership(Business $business, User $user, bool $lock = false): BusinessUser
    {
        $query = $user->businessMemberships()->where('business_id', $business->getKey());

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw ApiException::notFound('Staff user not found in this business.');
    }

    private function services(BusinessUser $membership): BelongsToMany
    {
        return $membership->services()
            ->where('services.business_id', $membership->business_id)
            ->with(['category', 'currentPrices', 'photos'])
            ->orderBy('services.name')
            ->orderBy('services.id');
    }
}
