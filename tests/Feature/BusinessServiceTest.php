<?php

use App\Models\Business;
use App\Services\BusinessService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

it('creates businesses with unique generated slugs and database defaults', function (): void {
    $service = app(BusinessService::class);
    $business = $service->create(['name' => 'Lotus Wellness Spa']);
    $secondBusiness = $service->create(['name' => 'Lotus Wellness Spa']);

    expect($business->slug)->toStartWith('lotus-wellness-spa-')
        ->not->toBe($secondBusiness->slug);
    expect($business->home_service)->toBeFalse()
        ->and($business->location_service)->toBeTrue()
        ->and($business->staff_number)->toBe(0);
    $this->assertDatabaseHas('businesses', ['id' => $business->id, 'name' => 'Lotus Wellness Spa', 'status' => 'pending']);
});

it('preserves a supplied slug and updates business attributes', function (): void {
    $service = app(BusinessService::class);
    $business = $service->create(['name' => 'Serenity Spa', 'slug' => 'serenity-spa']);
    $updated = $service->update($business, [
        'name' => 'Serenity Wellness Spa', 'home_service' => true, 'location_service' => false, 'staff_number' => 5,
    ]);

    expect($updated->slug)->toBe('serenity-spa')
        ->and($updated->home_service)->toBeTrue()
        ->and($updated->location_service)->toBeFalse()
        ->and($updated->staff_number)->toBe(5);
    $this->assertDatabaseHas('businesses', [
        'id' => $business->id, 'name' => 'Serenity Wellness Spa', 'home_service' => true, 'location_service' => false, 'staff_number' => 5,
    ]);
    expect($service->find($business->id)->name)->toBe('Serenity Wellness Spa');
});

it('soft deletes a business and excludes it from reads', function (): void {
    $service = app(BusinessService::class);
    $business = $service->create(['name' => 'Himalayan Bliss Spa']);
    $retained = $service->create(['name' => 'Sattva Day Spa']);

    expect($service->delete($business))->toBeTrue();
    $this->assertSoftDeleted('businesses', ['id' => $business->id]);
    expect(Business::withTrashed()->find($business->id))->not->toBeNull();
    expect(fn () => $service->find($business->id))->toThrow(ModelNotFoundException::class);
    expect($service->paginate()->pluck('id')->all())->toBe([$retained->id]);
});

it('paginates businesses in a stable order with bounded page sizes', function (): void {
    $this->freezeTime();
    $service = app(BusinessService::class);
    $first = $service->create(['name' => 'Lotus Ayurveda Retreat']);
    $second = $service->create(['name' => 'Serenity Spa']);
    $page = $service->paginate(1);

    expect($page->total())->toBe(2)
        ->and($page->perPage())->toBe(1)
        ->and($page->first()->id)->toBe(max($first->id, $second->id))
        ->and($service->paginate(0)->perPage())->toBe(1)
        ->and($service->paginate(200)->perPage())->toBe(100);
});

it('fails to find an unknown business', function (): void {
    expect(fn () => app(BusinessService::class)->find((string) Str::ulid()))->toThrow(ModelNotFoundException::class);
});

it('searches across business names, slugs, addresses, and cities', function (array $attributes): void {
    $service = app(BusinessService::class);
    $matched = $service->create(['name' => 'Wellness Studio', ...$attributes]);
    $service->create(['name' => 'Unrelated Spa', 'city' => 'Pokhara']);

    $page = $service->paginate(20, ['q' => '  LoTuS  ']);

    expect($page->total())->toBe(1)
        ->and($page->pluck('id')->all())->toBe([$matched->id]);
})->with([
    'name' => [['name' => 'Lotus Ayurveda Retreat']],
    'slug' => [['slug' => 'lotus-wellness']],
    'address' => [['address' => 'Lotus Lane, Thamel']],
    'city' => [['city' => 'Lotus Valley']],
]);

it('filters individual text fields with partial case insensitive matches', function (string $field): void {
    $service = app(BusinessService::class);
    $matched = $service->create(['name' => 'Wellness Studio', $field => 'Himalayan Lotus']);
    $service->create(['name' => 'Unrelated Spa', $field => 'Serenity']);

    expect($service->paginate(20, [$field => ' LoTuS '])->pluck('id')->all())->toBe([$matched->id]);
})->with(['name', 'slug', 'address', 'city']);

it('filters nearby coordinates and service flags including zero and false', function (array $filters, array $attributes): void {
    $service = app(BusinessService::class);
    $matched = $service->create(['name' => 'Lotus Spa', ...$attributes]);
    $service->create(['name' => 'Serenity Spa', 'latitude' => 27.7, 'longitude' => 85.3, 'home_service' => true, 'location_service' => false]);

    expect($service->paginate(20, $filters)->pluck('id')->all())->toBe([$matched->id]);
})->with([
    'zero latitude' => [['latitude' => 0, 'longitude' => 85.3], ['latitude' => 0, 'longitude' => 85.3]],
    'zero longitude' => [['latitude' => 27.7, 'longitude' => 0], ['latitude' => 27.7, 'longitude' => 0]],
    'both coordinates' => [['latitude' => 28.2, 'longitude' => 83.9], ['latitude' => 28.2, 'longitude' => 83.9]],
    'no home service' => [['home_service' => false], ['home_service' => false]],
    'location service' => [['location_service' => true], ['location_service' => true]],
    'zero home service' => [['home_service' => 0], ['home_service' => false]],
]);

it('combines filters and keeps search alternatives within the filtered result', function (): void {
    $service = app(BusinessService::class);
    $matched = $service->create(['name' => 'Lotus Spa', 'city' => 'Kathmandu', 'home_service' => true, 'location_service' => false]);
    $service->create(['name' => 'Lotus Spa', 'city' => 'Pokhara', 'home_service' => true, 'location_service' => false]);
    $service->create(['name' => 'Lotus Spa', 'city' => 'Kathmandu', 'home_service' => false]);
    $deleted = $service->create(['name' => 'Other Spa', 'address' => 'Lotus Lane', 'city' => 'Kathmandu', 'home_service' => true, 'location_service' => false]);
    $service->delete($deleted);

    $page = $service->paginate(20, ['q' => 'lotus', 'city' => 'kathmandu', 'home_service' => true, 'location_service' => false]);

    expect($page->total())->toBe(1)
        ->and($page->pluck('id')->all())->toBe([$matched->id]);
});

it('ignores empty filters and returns an empty page when no businesses match', function (): void {
    $service = app(BusinessService::class);
    $service->create(['name' => 'Serenity Spa']);

    expect($service->paginate(20, ['q' => ' ', 'city' => '', 'latitude' => null, 'home_service' => null])->total())->toBe(1)
        ->and($service->paginate(20, ['q' => 'nonexistent'])->total())->toBe(0);
});

it('returns nearby businesses closest first with correct paginated totals', function (): void {
    $service = app(BusinessService::class);
    $nearest = $service->create(['name' => 'Lotus Spa', 'latitude' => 27.7, 'longitude' => 85.3]);
    $nearby = $service->create(['name' => 'Serenity Spa', 'latitude' => 27.72, 'longitude' => 85.3]);
    $further = $service->create(['name' => 'Himalayan Spa', 'latitude' => 27.78, 'longitude' => 85.3]);
    $service->create(['name' => 'Distant Spa', 'latitude' => 28.2, 'longitude' => 83.9]);
    $service->create(['name' => 'Spa without a location']);
    $service->create(['name' => 'Spa with incomplete coordinates', 'latitude' => 27.7]);

    $filters = ['latitude' => 27.7, 'longitude' => 85.3];
    $page = $service->paginate(20, $filters);

    expect($page->total())->toBe(3)
        ->and($page->pluck('id')->all())->toBe([$nearest->id, $nearby->id, $further->id])
        ->and($page->first()->distance_km)->toBe(0.0)
        ->and($page->items()[1]->distance_km)->toBeGreaterThan(2.2)->toBeLessThan(2.3);
    $limited = $service->paginate(1, $filters);
    expect($limited->total())->toBe(3)
        ->and($limited->first()->id)->toBe($nearest->id)
        ->and($limited->lastPage())->toBe(3);
    expect($service->paginate(20, [...$filters, 'radius_km' => 3])->pluck('id')->all())->toBe([$nearest->id, $nearby->id])
        ->and($service->paginate(20, [...$filters, 'radius_km' => 1])->pluck('id')->all())->toBe([$nearest->id]);
});

it('combines proximity with text and service filters', function (): void {
    $service = app(BusinessService::class);
    $matched = $service->create(['name' => 'Lotus Spa', 'latitude' => 27.72, 'longitude' => 85.3, 'home_service' => true, 'location_service' => false]);
    $service->create(['name' => 'Lotus Day Spa', 'latitude' => 27.7, 'longitude' => 85.3, 'home_service' => false]);
    $service->create(['name' => 'Lotus Mountain Spa', 'latitude' => 28.2, 'longitude' => 83.9, 'home_service' => true, 'location_service' => false]);
    $deleted = $service->create(['name' => 'Lotus Retreat', 'latitude' => 27.7, 'longitude' => 85.3, 'home_service' => true, 'location_service' => false]);
    $service->delete($deleted);

    expect($service->paginate(20, [
        'q' => 'lotus', 'latitude' => 27.7, 'longitude' => 85.3, 'home_service' => true, 'location_service' => false,
    ])->pluck('id')->all())->toBe([$matched->id]);
});

it('finds nearby businesses across the international date line', function (): void {
    $service = app(BusinessService::class);
    $nearby = $service->create(['name' => 'Island Wellness Spa', 'latitude' => 0, 'longitude' => -179.99]);
    $service->create(['name' => 'Distant Island Spa', 'latitude' => 0, 'longitude' => 179]);

    expect($service->paginate(20, ['latitude' => 0, 'longitude' => 179.99, 'radius_km' => 5])->pluck('id')->all())->toBe([$nearby->id]);
});

it('rejects incomplete or invalid proximity filters', function (array $filters): void {
    expect(fn () => app(BusinessService::class)->paginate(20, $filters))
        ->toThrow(ValidationException::class);
})->with([
    'latitude without longitude' => [['latitude' => 27.7]],
    'longitude without latitude' => [['longitude' => 85.3]],
    'radius without a location' => [['radius_km' => 5]],
    'invalid latitude' => [['latitude' => 91, 'longitude' => 85.3]],
    'invalid longitude' => [['latitude' => 27.7, 'longitude' => -181]],
    'nonnumeric latitude' => [['latitude' => 'nearby', 'longitude' => 85.3]],
    'zero radius' => [['latitude' => 27.7, 'longitude' => 85.3, 'radius_km' => 0]],
    'negative radius' => [['latitude' => 27.7, 'longitude' => 85.3, 'radius_km' => -1]],
]);

it('checks current verification state even when the supplied business instance is stale', function (): void {
    Storage::fake('local');
    $service = app(BusinessService::class);
    $business = $service->create(['name' => 'Lotus Spa']);
    Business::query()->whereKey($business->id)->update(['is_verified' => true]);

    expect(fn () => $service->update($business, [
        'is_verified' => false,
        'kyc_documents' => ['vat_pan_document' => UploadedFile::fake()->create('pan.pdf', 100, 'application/pdf')],
    ]))->toThrow(ValidationException::class);

    expect($business->refresh()->is_verified)->toBeTrue()
        ->and($business->kyc_documents)->toBeNull();
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('prevents direct model saves from changing verified KYC documents', function (): void {
    $business = app(BusinessService::class)->create(['name' => 'Lotus Spa', 'is_verified' => true]);

    expect(fn () => $business->forceFill([
        'is_verified' => false, 'kyc_documents' => ['vat_pan_document' => 'replacement.pdf'],
    ])->save())->toThrow(ValidationException::class);

    expect($business->refresh()->kyc_documents)->toBeNull()
        ->and($business->is_verified)->toBeTrue();
});

it('cleans up new files and retains previous uploads when saving the update fails', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $service = app(BusinessService::class);
    $business = $service->create(['name' => 'Lotus Spa']);
    $other = $service->create(['name' => 'Serenity Spa']);
    $image = 'business-images/'.$business->id.'/original.jpg';
    $document = 'business-kyc/'.$business->id.'/original.pdf';
    Storage::disk('public')->put($image, 'original image');
    Storage::disk('local')->put($document, 'original document');
    $business->forceFill(['hero_image_path' => $image, 'kyc_documents' => ['vat_pan_document' => $document]])->save();

    expect(fn () => $service->update($business, [
        'slug' => $other->slug,
        'hero_image' => UploadedFile::fake()->image('replacement.jpg'),
        'kyc_documents' => ['vat_pan_document' => UploadedFile::fake()->create('replacement.pdf', 100, 'application/pdf')],
    ]))->toThrow(QueryException::class);

    expect($business->refresh()->hero_image_path)->toBe($image)
        ->and($business->kyc_documents)->toBe(['vat_pan_document' => $document]);
    expect(Storage::disk('public')->allFiles())->toBe([$image]);
    expect(Storage::disk('local')->allFiles())->toBe([$document]);
});

it('stores business images during creation and cleans them up if creation fails', function (): void {
    Storage::fake('public');
    $service = app(BusinessService::class);
    $business = $service->create([
        'name' => 'Lotus Spa', 'slug' => 'lotus-spa', 'hero_image' => UploadedFile::fake()->image('spa.jpg'),
    ]);

    expect($business->hero_image_path)->toStartWith('business-images/'.$business->id.'/');
    Storage::disk('public')->assertExists($business->hero_image_path);
    expect(fn () => $service->create([
        'name' => 'Another Lotus Spa', 'slug' => 'lotus-spa', 'hero_image' => UploadedFile::fake()->image('other.jpg'),
    ]))->toThrow(QueryException::class);
    expect(Storage::disk('public')->allFiles())->toBe([$business->hero_image_path]);
    $this->assertDatabaseCount('businesses', 1);
});
