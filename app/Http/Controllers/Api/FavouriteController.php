<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FavouriteServiceResource;
use App\Models\Service;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavouriteController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $services = $request->user()->favouriteServices()
            ->orderByPivotDesc('id')
            ->paginate($perPage, [
                'services.id',
                'services.business_id',
                'services.name',
                'services.description',
                'services.image_path',
            ]);

        return $this->paginated(FavouriteServiceResource::collection($services));
    }

    public function store(Request $request, Service $service): JsonResponse
    {
        $request->user()->favouriteServices()->newPivotStatement()->insertOrIgnore([
            'user_id' => $request->user()->getKey(),
            'service_id' => $service->getKey(),
        ]);

        return $this->state($service->getKey(), true);
    }

    public function destroy(Request $request, string $serviceId): JsonResponse
    {
        $request->user()->favouriteServices()->detach($serviceId);

        return $this->state($serviceId, false);
    }

    private function state(string $serviceId, bool $isFavourite): JsonResponse
    {
        return $this->success(['service_id' => $serviceId, 'is_favourite' => $isFavourite]);
    }
}
