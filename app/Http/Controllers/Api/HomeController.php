<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Dto\Api\CatalogData;
use App\Http\Resources\BusinessResource;
use App\Models\Business;
use App\Models\Category;
use App\Models\Service;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    use ApiResponse;

    public function home(Request $request): JsonResponse
    {
        $user = $request->user();

        $services = Service::query()
            ->whereHas('business', fn ($query) => $query->where('status', 'active')->where('is_online', true))
            ->where('status', 'active')
            ->with(['business', 'currentPrices'])
            ->orderBy('name')
            ->get();

        $services->each(function (Service $service): void {
            $price = $service->currentPrices->first();
            $service->price = $price?->price_minor;
            $service->currency = $price?->currency;
        });

        $categories = Category::query()->where('is_active', true)->orderBy('sort_order')->get();

        return $this->success((new CatalogData($user, $categories, $services, app(NotificationService::class)->unreadCount($user)))->toArray(), 'Home catalog loaded.');
    }

    public function businesses(Request $request): JsonResponse
    {
        $query = Business::query()
            ->where('status', 'active')
            ->where('is_online', true)
            ->withCount(['services as services_count' => fn ($q) => $q->where('status', 'active')]);

        if ($search = trim((string) $request->query('q', ''))) {
            $like = '%'.strtolower($search).'%';

            $query->where(function ($q) use ($like): void {
                $q->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhere('about', 'like', $like)
                    ->orWhereHas('services', fn ($s) => $s->where('search_name', 'like', $like)->orWhere('name', 'like', $like));
            });
        }

        $filters = $request->validate([
            'city' => ['sometimes', 'string', 'max:120'],
            'address' => ['sometimes', 'string', 'max:255'],
        ]);

        if (isset($filters['city'])) {
            $query->where('city', $filters['city']);
        }

        if (isset($filters['address'])) {
            $query->where('address', 'like', '%'.$filters['address'].'%');
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
