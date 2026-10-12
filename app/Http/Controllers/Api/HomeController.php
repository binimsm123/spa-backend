<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Dto\Api\CatalogData;
use App\Http\Requests\Catalog\BusinessCatalogRequest;
use App\Http\Resources\BusinessCatalogResource;
use App\Http\Resources\BusinessResource;
use App\Models\Business;
use App\Models\Service;
use App\Services\BusinessService;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BusinessService $businesses,
        private readonly NotificationService $notifications,
    ) {}

    public function home(BusinessCatalogRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $categories = $this->businesses->paginateCategories((int) ($filters['per_page'] ?? 20), $filters);

        return $this->success((new CatalogData($request->user(), $categories, $this->notifications->unreadCount($request->user())))->toArray(), 'Home catalog loaded.');
    }

    public function newHome(BusinessCatalogRequest $request): JsonResponse
    {
        $categories = $this->businesses->categoriesWithBusiness($request->validated(), loadServices: false);

        return $this->success((new CatalogData($request->user(), $categories, $this->notifications->unreadCount($request->user())))->toArray(), 'Home catalog loaded.');
    }

    public function businesses(BusinessCatalogRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $businesses = $this->businesses->paginate((int) ($filters['per_page'] ?? 20), $filters, catalog: true);

        return $this->paginated(BusinessCatalogResource::collection($businesses), 'Catalog loaded.');
    }

    public function businessDetail(Request $request, Business $business): JsonResponse
    {
        $business->load(['locations', 'categories']);

        if ($business->status !== 'active' || ! $business->is_online) {
            return $this->resource(new BusinessResource($business), 'This business is not currently bookable.');
        }

        $business->load([
            'hours',
            'closures',
            'services' => fn ($q) => $q->where('status', 'active')->with(['category', 'currentPrices']),
            'offers' => fn ($q) => $q->where('status', 'active')->where('starts_at', '<=', now())->where('expires_at', '>=', now()),
            'reviews' => fn ($q) => $q->where('is_hidden', false)->with('user')->latest()->limit(10),
        ]);

        $business->services->each(function (Service $service): void {
            $price = $service->currentPrices->first();

            if ($price !== null) {
                $service->price = $price->price_minor;
                $service->currency = $price->currency;
            }
        });

        $overview = [
            'total_reviews' => (int) $business->rating_count,
            'rating_average' => (float) $business->rating_average,
            'services_count' => $business->services->count(),
            'active_offers' => $business->offers->count(),
        ];

        $payload = BusinessResource::make($business)->resolve();
        $payload['overview'] = $overview;

        return $this->success($payload);
    }
}
