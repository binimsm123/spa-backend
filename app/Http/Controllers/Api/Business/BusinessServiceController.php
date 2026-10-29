<?php

namespace App\Http\Controllers\Api\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\UpsertServiceRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ServiceResource;
use App\Models\Business;
use App\Models\Category;
use App\Models\Service;
use App\Services\BusinessManagement;
use App\Services\ServiceManagement;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessServiceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BusinessManagement $management,
        private readonly ServiceManagement $services,
    ) {}

    public function index(Request $request, Business $business): JsonResponse
    {
        $this->management->authorize($request, $business);
        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'], 'category_id' => ['sometimes', 'nullable', 'ulid'],
        ]);
        $query = $business->services()->with(['category', 'currentPrices', 'photos'])->orderBy('name')->orderBy('id');
        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereRaw('LOWER(description) LIKE ?', ['%'.mb_strtolower($search).'%']));
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }
        if ($request->hasAny(['page', 'per_page'])) {
            $page = $query->paginate($request->integer('per_page', 20));
            $page->getCollection()->each(fn ($service) => $this->addSummaryPrice($service));

            return $this->paginated(ServiceResource::collection($page));
        }
        $services = $query->get()->each(fn ($service) => $this->addSummaryPrice($service));

        return $this->success(['items' => ServiceResource::collection($services)->resolve()]);
    }

    public function categories(Request $request, Business $business): JsonResponse
    {
        $this->management->authorize($request, $business);

        return $this->success(['items' => CategoryResource::collection(Category::where('is_active', true)->orderBy('sort_order')->orderBy('name')->orderBy('id')->get())->resolve()]);
    }

    public function show(Request $request, Business $business, Service $service): JsonResponse
    {
        $this->assertRecord($request, $business, $service);

        return $this->resource(new ServiceResource($this->loaded($service)));
    }

    public function store(UpsertServiceRequest $request, Business $business): JsonResponse
    {
        return $this->save($request, $business);
    }

    public function update(UpsertServiceRequest $request, Business $business, Service $service): JsonResponse
    {
        $this->assertRecord($request, $business, $service);

        return $this->save($request, $business, $service);
    }

    private function save(UpsertServiceRequest $request, Business $business, ?Service $service = null): JsonResponse
    {
        $data = $this->services->normalize($request->validated());
        $modern = isset($data['management_version']) || isset($data['prices']) || isset($data['images']);

        return $this->management->mutate($request, $business, $service ? 'service.update.'.$service->id : 'service.create.'.$business->id, $data, function () use ($request, $business, $service, $data): array {
            $record = $this->services->save($business, $data, $this->management, $service);
            $this->addSummaryPrice($record);

            return [$service ? 200 : 201, (new ServiceResource($record))->resolve($request)];
        }, $modern);
    }

    public function destroy(Request $request, Business $business, Service $service): JsonResponse
    {
        $this->assertRecord($request, $business, $service);

        return $this->management->mutate($request, $business, 'service.delete.'.$service->id, [], function () use ($service): array {
            $this->services->delete($service, $this->management);

            return [200, null];
        }, false);
    }

    private function assertRecord(Request $request, Business $business, Service $service): void
    {
        $this->management->authorize($request, $business);
        abort_unless($service->business_id === $business->id, 404);
    }

    private function loaded(Service $service): Service
    {
        $service->load(['category', 'currentPrices', 'photos']);
        $this->addSummaryPrice($service);

        return $service;
    }

    private function addSummaryPrice(Service $service): void
    {
        if ($price = $service->currentPrices->first()) {
            $service->price = $price->price_minor;
            $service->currency = $price->currency;
        }
    }
}
