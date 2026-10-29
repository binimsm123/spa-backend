<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Category;
use App\Models\Service;
use App\Models\ServicePhoto;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ServiceManagement
{
    /** @param array<string, mixed> $data */
    public function normalize(array $data): array
    {
        if (isset($data['management_version'])) {
            $data['management_version'] = 1;
        }

        if (isset($data['prices'])) {
            $data['prices'] = array_map(fn (array $price): array => [
                'duration' => $price['duration'],
                'price_minor' => (int) $price['price_minor'],
                'currency' => $price['currency'],
            ], $data['prices']);
            usort($data['prices'], fn (array $first, array $second): int => $first['duration'] <=> $second['duration']);
        }

        return $data;
    }

    /**
     * Save within BusinessManagement's transaction and upload lifecycle.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(Business $business, array $data, BusinessManagement $management, ?Service $service = null): Service
    {
        $record = $service
            ? $business->services()->whereKey($service->getKey())->lockForUpdate()->firstOrFail()
            : new Service(['business_id' => $business->getKey()]);
        $category = null;

        if (isset($data['category_id']) && $record->category_id !== $data['category_id']) {
            $category = Category::query()->whereKey($data['category_id'])->where('is_active', true)->first();

            if ($category === null) {
                throw ValidationException::withMessages(['category_id' => 'Choose an active service category.']);
            }
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
        $this->savePhotos($record, $data, $management);

        if ($category !== null) {
            $record->setRelation('category', $category);
        }

        return $record->loadMissing('category');
    }

    private function priceKey(string $duration, int $amount, string $currency): string
    {
        return substr($duration, 0, 5).'|'.$amount.'|'.$currency;
    }

    /** @param array<string, mixed> $data */
    private function savePrices(Service $service, array $data): void
    {
        if (isset($data['prices'])) {
            $existing = [];

            if (! $service->wasRecentlyCreated) {
                foreach ($service->currentPrices()->get() as $price) {
                    $key = $this->priceKey($price->duration, $price->price_minor, $price->currency);
                    $existing[$key] ??= $price;
                }
            }

            $prices = new Collection;

            foreach ($data['prices'] as $price) {
                $key = $this->priceKey($price['duration'], $price['price_minor'], $price['currency']);
                $prices->push($existing[$key] ?? $service->prices()->create($price + ['is_current' => true]));
            }

            if (! $service->wasRecentlyCreated) {
                $service->prices()->where('is_current', true)->whereNotIn('id', $prices->modelKeys())->update(['is_current' => false]);
            }

            $service->setRelation('currentPrices', $prices);

            return;
        }

        if (isset($data['price_minor']) || isset($data['duration']) || isset($data['currency'])) {
            $current = $service->wasRecentlyCreated ? null : $service->currentPrices()->first();

            if (! $service->wasRecentlyCreated) {
                $service->prices()->where('is_current', true)->update(['is_current' => false]);
            }

            $price = $service->prices()->create([
                'price_minor' => $data['price_minor'] ?? $current?->price_minor ?? 0,
                'currency' => $data['currency'] ?? $current?->currency ?? 'NPR',
                'duration' => $data['duration'] ?? $current?->duration ?? '01:00',
                'is_current' => true,
            ]);
            $service->setRelation('currentPrices', new Collection([$price]));

            return;
        }

        $service->loadMissing('currentPrices');
    }

    /** @param array<string, mixed> $data */
    private function savePhotos(Service $service, array $data, BusinessManagement $management): void
    {
        $photos = $service->wasRecentlyCreated ? new Collection : $service->photos()->get();

        if (array_key_exists('retained_photo_ids', $data) || isset($data['images'])) {
            $photos = $this->syncPhotos($service, $photos, $data, $management);
            $service->image_path = $photos->first()?->path;
            $service->save();
        } elseif ($service->wasRecentlyCreated && (isset($data['management_version']) || isset($data['prices']))) {
            throw ValidationException::withMessages(['images' => 'Add at least one service photo.']);
        } elseif (array_key_exists('image_path', $data)) {
            foreach ($photos as $photo) {
                if ($photo->path !== $data['image_path']) {
                    $management->removeAfterCommit('public', $photo->path);
                }

                $photo->delete();
            }

            $photos = new Collection;

            if ($data['image_path']) {
                $photos->push($service->photos()->create(['path' => $data['image_path'], 'position' => 0]));
            }
        }

        $service->setRelation('photos', $photos);
    }

    /**
     * @param  Collection<int, ServicePhoto>  $photos
     * @param  array<string, mixed>  $data
     * @return Collection<int, ServicePhoto>
     */
    private function syncPhotos(Service $service, Collection $photos, array $data, BusinessManagement $management): Collection
    {
        $photosById = $photos->keyBy('id');
        $retainedIds = $data['retained_photo_ids'] ?? $photos->modelKeys();

        if (array_diff($retainedIds, $photos->modelKeys())) {
            throw ValidationException::withMessages(['retained_photo_ids' => 'A retained photo does not belong to this service.']);
        }

        if (count($retainedIds) + count($data['images'] ?? []) < 1) {
            throw ValidationException::withMessages(['images' => 'Keep at least one service photo.']);
        }

        $retained = array_flip($retainedIds);

        foreach ($photos as $photo) {
            if (! isset($retained[$photo->id])) {
                $photo->delete();
                $management->removeAfterCommit('public', $photo->path);
            }
        }

        $saved = new Collection;
        $positions = [];

        foreach ($retainedIds as $position => $id) {
            $photo = $photosById->get($id);
            $photo->position = $position;
            $positions[] = ['id' => $photo->id, 'service_id' => $photo->service_id, 'path' => $photo->path, 'position' => $position];
            $saved->push($photo);
        }

        if ($positions !== []) {
            ServicePhoto::query()->upsert($positions, ['id'], ['position']);
        }

        foreach ($data['images'] ?? [] as $index => $file) {
            $path = $management->upload($file, 'public', 'service-photos/'.$service->business_id.'/'.$service->id);
            $saved->push($service->photos()->create(['path' => $path, 'position' => count($retainedIds) + $index]));
        }

        return $saved->sortBy([['position', 'asc'], ['id', 'asc']])->values();
    }

    public function delete(Service $service, BusinessManagement $management): void
    {
        $record = Service::query()->whereKey($service->getKey())->lockForUpdate()->firstOrFail();

        foreach ($record->photos()->cursor() as $photo) {
            $management->removeAfterCommit('public', $photo->path);
        }

        $management->removeAfterCommit('public', $record->image_path);
        $record->photos()->delete();
        $record->forceFill(['image_path' => null])->save();
        $record->delete();
    }
}
