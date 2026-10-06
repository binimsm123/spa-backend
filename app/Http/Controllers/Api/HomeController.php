<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Dto\Api\CatalogData;
use App\Http\Dto\Api\LocationsData;
use App\Http\Resources\BusinessResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\OfferResource;
use App\Http\Resources\ServiceResource;
use App\Http\Resources\UserResource;
use App\Models\Business;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    use ApiResponse;

    public function home(Request $request): JsonResponse
    {
        $user = $request->user();

        $services = Service::query()
            ->join('businesses', 'businesses.id', '=', 'services.business_id')
            ->leftJoin('service_prices', function ($join): void {
                $join->on('service_prices.service_id', '=', 'services.id')
                    ->where('service_prices.is_current', true);
            })
            ->where('businesses.status', 'active')
            ->where('businesses.is_online', true)
            ->where('services.status', 'active')
            ->orderBy('services.name')
            ->distinct()
            ->get([
                'services.*',
                'service_prices.price_minor as price',
                'service_prices.currency',
            ]);

        $categories = Category::query()->where('is_active', true)->orderBy('sort_order')->get();

        $services->load('business');

        return $this->success((new CatalogData($user, $user->selectedLocation, $categories, $services, app(\App\Services\NotificationService::class)->unreadCount($user)))->toArray(), 'Home catalog loaded.');
    }

    public function businesses(Request $request): JsonResponse
    {
        $query = Business::query()
            ->where('status', 'active')
            ->where('is_online', true)
            ->with(['locations' => fn ($q) => $q->where('is_active', true)])
            ->withCount(['services as services_count' => fn ($q) => $q->where('status', 'active')]);

        if ($search = trim((string) $request->query('q', ''))) {
            $like = '%'.strtolower($search).'%';

            $query->where(function ($q) use ($like): void {
                $q->where('search_name', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('about', 'like', $like)
                    ->orWhereHas('services', fn ($s) => $s->where('search_name', 'like', $like)->orWhere('name', 'like', $like));
            });
        }

        if ($locationId = $request->query('location_id')) {
            $query->whereHas('locations', fn ($q) => $q->where('location_id', $locationId)->where('is_active', true));
        }

        if ($request->boolean('verified_only')) {
            $query->where('is_verified', true);
        }

        match ($request->query('sort', 'rating')) {
            'name' => $query->orderBy('name'),
            'newest' => $query->latest(),
            default => $query->orderByDesc('rating_average')->orderBy('name'),
        };

        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        return $this->paginated(BusinessResource::collection($query->paginate($perPage)), 'Catalog loaded.');
    }

    public function businessDetail(Request $request, Business $business): JsonResponse
    {
        if ($business->status !== 'active' || ! $business->is_online) {
            return $this->resource(new BusinessResource($business), 'This business is not currently bookable.');
        }

        $business->load([
            'locations' => fn ($q) => $q->where('is_active', true),
            'services' => fn ($q) => $q->where('status', 'active')->with('category'),
            'offers' => fn ($q) => $q->where('status', 'active')->where('starts_at', '<=', now())->where('expires_at', '>=', now()),
            'reviews' => fn ($q) => $q->where('is_hidden', false)->with('user')->latest()->limit(10),
        ]);

        // Attach current prices per service (cheapest branch price).
        $prices = ServicePrice::query()
            ->whereIn('service_id', $business->services->modelKeys())
            ->where('is_current', true)
            ->get()
            ->groupBy('service_id')
            ->map->first();

        $business->services->each(function (Service $service) use ($prices): void {
            $price = $prices->get($service->getKey());

            if ($price !== null) {
                $service->setRelation('price', $price->price_minor);
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
