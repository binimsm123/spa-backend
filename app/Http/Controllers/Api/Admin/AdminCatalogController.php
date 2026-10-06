<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ServiceResource;
use App\Models\Category;
use App\Models\Service;
use App\Support\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminCatalogController extends Controller
{
    use ApiResponse;

    public function categories(Request $request): JsonResponse
    {
        $query = Category::query()
            ->when($request->query('include_inactive') !== 'true', fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order');

        return $this->success(['items' => CategoryResource::collection($query->get())->resolve()]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $category = Category::query()->create($data + ['slug' => Str::slug($data['name'])]);

        AuditLogger::record($request->user(), 'category.created', 'category', $category->getKey(), null, $category->only(['name', 'slug']));

        return $this->resource(CategoryResource::make($category), 'Category created.', 201);
    }

    public function updateCategory(Request $request, Category $category): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $before = $category->only(['name', 'sort_order', 'is_active']);
        $category->fill($data)->save();

        AuditLogger::record($request->user(), 'category.updated', 'category', $category->getKey(), $before, $category->only(['name', 'sort_order', 'is_active']));

        return $this->resource(CategoryResource::make($category->refresh()), 'Category updated.');
    }

    public function services(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $query = Service::query()
            ->with(['business', 'category'])
            ->when($request->query('q'), function ($q, $search): void {
                $like = '%'.strtolower($search).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(search_name) LIKE ?', [$like]));
            })
            ->when($request->query('business_id'), fn ($q, $id) => $q->where('business_id', $id))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at');

        return $this->paginated(ServiceResource::collection($query->paginate($perPage)));
    }

    public function updateService(Request $request, Service $service): JsonResponse
    {
        $data = $request->validate([
            'is_bookable' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:active,inactive'],
            'max_people' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $before = $service->only(['is_bookable', 'status', 'max_people']);
        $service->fill($data)->save();

        AuditLogger::record($request->user(), 'service.updated', 'service', $service->getKey(), $before, $service->only(['is_bookable', 'status', 'max_people']));

        return $this->resource(ServiceResource::make($service->refresh()), 'Service updated.');
    }
}
