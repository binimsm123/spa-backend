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
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BusinessServiceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BusinessManagement $management) {}

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
        $data = $request->validated();
        if (isset($data['management_version'])) {
            $data['management_version'] = 1;
        }
        if (isset($data['prices'])) {
            $data['prices'] = array_map(fn ($p) => ['duration_minutes' => (int) $p['duration_minutes'], 'price_minor' => (int) $p['price_minor'], 'currency' => $p['currency']], $data['prices']);
            usort($data['prices'], fn ($a, $b) => $a['duration_minutes'] <=> $b['duration_minutes']);
        }
        $modern = isset($data['management_version']) || isset($data['prices']) || isset($data['images']);

        return $this->management->mutate($request, $business, $service ? 'service.update.'.$service->id : 'service.create.'.$business->id, $data, function () use ($request, $business, $service, $data, $modern): array {
            $record = $service ? Service::whereKey($service->id)->where('business_id', $business->id)->lockForUpdate()->firstOrFail() : new Service(['business_id' => $business->id]);
            if (isset($data['category_id']) && $record->category_id !== $data['category_id'] && ! Category::whereKey($data['category_id'])->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['category_id' => 'Choose an active service category.']);
            }
            $record->fill(Arr::only($data, ['category_id', 'name', 'description', 'image_path', 'max_people', 'is_bookable', 'status']));
            if (! $record->exists) {
                $record->slug = Str::slug($data['name']).'-'.Str::lower(Str::random(8));
            }
            if (isset($data['name'])) {
                $record->search_name = mb_strtolower($data['name']);
            }
            $record->save();
            $this->savePrices($record, $data);
            $this->savePhotos($record, $data, $modern, $service === null);

            return [$service ? 200 : 201, (new ServiceResource($this->loaded($record)))->resolve($request)];
        }, $modern);
    }

    private function savePrices(Service $service, array $data): void
    {
        if (isset($data['prices'])) {
            $current = $service->currentPrices()->get();
            $retained = [];
            foreach ($data['prices'] as $price) {
                $duration = sprintf('%02d:%02d:00', intdiv($price['duration_minutes'], 60), $price['duration_minutes'] % 60);
                $matching = $current->first(fn ($p) => $p->durationMinutes() === $price['duration_minutes'] && $p->price_minor === $price['price_minor'] && $p->currency === $price['currency']);
                $saved = $matching ?? $service->prices()->create(Arr::except($price, ['duration_minutes']) + ['duration' => $duration, 'is_current' => true]);
                $retained[] = $saved->id;
            }
            $service->prices()->where('is_current', true)->whereNotIn('id', $retained)->update(['is_current' => false]);
        } elseif (isset($data['price_minor']) || isset($data['duration']) || isset($data['currency'])) {
            $current = $service->currentPrices()->first();
            $service->prices()->where('is_current', true)->update(['is_current' => false]);
            $service->prices()->create([
                'price_minor' => $data['price_minor'] ?? $current?->price_minor ?? 0,
                'currency' => $data['currency'] ?? $current?->currency ?? 'NPR',
                'duration' => $data['duration'] ?? $current?->duration ?? '01:00', 'is_current' => true,
            ]);
        }
    }

    private function savePhotos(Service $service, array $data, bool $modern, bool $creating): void
    {
        $photos = $service->photos()->get();
        $changes = array_key_exists('retained_photo_ids', $data) || isset($data['images']);
        if ($changes) {
            $retainedIds = $data['retained_photo_ids'] ?? $photos->pluck('id')->all();
            if (array_diff($retainedIds, $photos->pluck('id')->all())) {
                throw ValidationException::withMessages(['retained_photo_ids' => 'A retained photo does not belong to this service.']);
            }
            $count = count($retainedIds) + count($data['images'] ?? []);
            if ($count < 1 || $count > 2) {
                throw ValidationException::withMessages(['images' => 'Keep between one and two service photos.']);
            }
            foreach ($photos as $photo) {
                if (! in_array($photo->id, $retainedIds, true)) {
                    $photo->delete();
                    $this->management->removeAfterCommit('public', $photo->path);
                }
            }
            foreach ($retainedIds as $position => $id) {
                $service->photos()->whereKey($id)->update(['position' => $position]);
            }
            foreach ($data['images'] ?? [] as $index => $file) {
                $path = $this->management->upload($file, 'public', 'service-photos/'.$service->business_id.'/'.$service->id);
                $service->photos()->create(['path' => $path, 'position' => count($retainedIds) + $index]);
            }
            $service->image_path = $service->photos()->first()?->path;
            $service->save();
        } elseif ($modern && $creating) {
            throw ValidationException::withMessages(['images' => 'Add at least one service photo.']);
        } elseif (array_key_exists('image_path', $data)) {
            foreach ($photos as $photo) {
                if ($photo->path !== $data['image_path']) {
                    $this->management->removeAfterCommit('public', $photo->path);
                }
                $photo->delete();
            }
            if ($data['image_path']) {
                $service->photos()->create(['path' => $data['image_path'], 'position' => 0]);
            }
        }
    }

    public function destroy(Request $request, Business $business, Service $service): JsonResponse
    {
        $this->assertRecord($request, $business, $service);

        return $this->management->mutate($request, $business, 'service.delete.'.$service->id, [], function () use ($service): array {
            $service->refresh()->delete();

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
