<?php

use App\Models\Business;
use App\Models\User;
use App\Services\BusinessService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function businessLocationPayload(array $overrides = []): array
{
    return [...[
        'address' => 'Street 12', 'city' => 'Kathmandu', 'latitude' => 27.7, 'longitude' => 85.3,
        'service_availability' => 'on_site',
    ], ...$overrides];
}

function businessLocationOwner(): array
{
    $owner = User::factory()->create();
    [$business] = createBusinessWithService();
    $business->members()->attach($owner->id, ['role' => 'owner', 'is_active' => true]);

    return [$owner, $business];
}

it('registers multiple locations belonging to the pending business', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $payload = ['name' => 'Lotus Spa', 'phone_number' => '9800000000', 'locations' => [
        businessLocationPayload(), businessLocationPayload(['city' => 'Lalitpur', 'service_availability' => 'home']),
    ]];
    foreach (Business::KYC_DOCUMENT_TYPES as $type) {
        $payload[$type] = UploadedFile::fake()->create($type.'.pdf', 20, 'application/pdf');
    }

    $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/business/register', $payload)
        ->assertCreated()->assertJsonCount(2, 'data.locations')->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.locations.1.service_availability', 'home');
    $business = Business::query()->findOrFail($response->json('data.id'));
    expect($business->locations)->toHaveCount(2)
        ->and($business->locations->first()->business->id)->toBe($business->id);
    $this->assertDatabaseHas('business_locations', ['business_id' => $business->id, 'city' => 'Lalitpur', 'service_availability' => 'home']);
    expect(Schema::hasColumn('businesses', 'address'))->toBeFalse()
        ->and(Schema::hasColumn('businesses', 'city'))->toBeFalse()
        ->and(Schema::hasColumn('businesses', 'latitude'))->toBeFalse()
        ->and(Schema::hasColumn('businesses', 'longitude'))->toBeFalse();
});

it('replaces locations only for the business managed by the owner', function (): void {
    [$owner, $business] = businessLocationOwner();
    $other = app(BusinessService::class)->create(['name' => 'Other Spa', 'locations' => [businessLocationPayload()]]);
    $originalId = $other->locations->first()->id;

    $this->actingAs($owner, 'sanctum')->patchJson('/api/v1/business/'.$business->id, [
        'locations' => [businessLocationPayload(['city' => 'Pokhara']), businessLocationPayload(['service_availability' => 'home'])],
    ])->assertOk()->assertJsonCount(2, 'data.locations')->assertJsonPath('data.locations.0.city', 'Pokhara');
    expect($business->locations()->count())->toBe(2)
        ->and($other->refresh()->locations->first()->id)->toBe($originalId);

    $this->patchJson('/api/v1/business/'.$other->id, ['locations' => [businessLocationPayload(['city' => 'Bhaktapur'])]])->assertForbidden();
    $this->assertDatabaseHas('business_locations', ['id' => $originalId, 'city' => 'Kathmandu']);
});

it('rejects invalid locations without changing the business or its existing locations', function (array $location, string $field): void {
    [$owner, $business] = businessLocationOwner();
    app(BusinessService::class)->update($business, ['locations' => [businessLocationPayload()]]);
    $locationId = $business->locations()->first()->id;
    $name = $business->name;

    $this->actingAs($owner, 'sanctum')->patchJson('/api/v1/business/'.$business->id, [
        'name' => 'Changed Spa', 'locations' => [$location],
    ])->assertUnprocessable()->assertJsonValidationErrors([$field]);
    $this->assertDatabaseHas('businesses', ['id' => $business->id, 'name' => $name]);
    $this->assertDatabaseHas('business_locations', ['id' => $locationId, 'city' => 'Kathmandu']);
})->with([
    'missing fields' => [[], 'locations.0.address'],
    'invalid availability' => [businessLocationPayload(['service_availability' => 'both']), 'locations.0.service_availability'],
    'invalid latitude' => [businessLocationPayload(['latitude' => 91]), 'locations.0.latitude'],
    'invalid longitude' => [businessLocationPayload(['longitude' => -181]), 'locations.0.longitude'],
    'foreign business id' => [businessLocationPayload(['business_id' => 'another-business']), 'locations.0'],
]);

it('finds businesses by a secondary location without duplicating nearby results', function (): void {
    $service = app(BusinessService::class);
    $business = $service->create(['name' => 'Lotus Spa', 'locations' => [
        businessLocationPayload(['city' => 'Pokhara', 'latitude' => 28.2, 'longitude' => 83.9]),
        businessLocationPayload(), businessLocationPayload(['address' => 'Secondary Street']),
    ]]);
    expect($service->paginate(20, ['city' => 'Kathmandu'])->pluck('id')->all())->toBe([$business->id])
        ->and($service->paginate(20, ['latitude' => 27.7, 'longitude' => 85.3])->pluck('id')->all())->toBe([$business->id]);

    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/locations')
        ->assertOk()->assertJsonPath('data', ['Kathmandu', 'Pokhara']);
});

it('rolls back business creation when a location is invalid', function (): void {
    expect(fn () => app(BusinessService::class)->create([
        'name' => 'Invalid Spa', 'locations' => [businessLocationPayload(['service_availability' => 'invalid'])],
    ]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('businesses', 0);
    $this->assertDatabaseCount('business_locations', 0);
});

it('filters customer and admin catalogs using secondary location addresses', function (): void {
    $business = app(BusinessService::class)->create([
        'name' => 'Lotus Spa', 'status' => 'active', 'is_online' => true, 'locations' => [
            businessLocationPayload(), businessLocationPayload(['city' => 'Lalitpur', 'address' => 'Patan Road']),
        ],
    ]);
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/businesses?city=Lalitpur&address=Patan')
        ->assertOk()->assertJsonPath('data.items.0.id', $business->id)->assertJsonCount(2, 'data.items.0.locations');
    actingAsSuperadmin();
    $this->getJson('/api/v1/admin/businesses?q=Patan')->assertOk()->assertJsonPath('data.items.0.id', $business->id);
});

it('creates and drops the business locations table independently of business addresses', function (): void {
    $originalConnection = DB::getDefaultConnection();
    config(['database.connections.location_migration_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::setDefaultConnection('location_migration_test');

    try {
        Schema::create('businesses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
        });
        $businessId = (string) Str::ulid();
        DB::table('businesses')->insert(['id' => $businessId]);
        $migration = require database_path('migrations/2026_01_01_000005_create_business_locations_table.php');
        $migration->up();
        foreach (['home', 'on_site'] as $availability) {
            DB::table('business_locations')->insert([
                'id' => (string) Str::ulid(), 'business_id' => $businessId,
                ...businessLocationPayload(['service_availability' => $availability]),
            ]);
        }
        $this->assertDatabaseCount('business_locations', 2);

        $migration->down();
        expect(Schema::hasTable('business_locations'))->toBeFalse();
        $this->assertDatabaseHas('businesses', ['id' => $businessId]);
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge('location_migration_test');
    }
});

it('requires address and nearby filters to match the same location', function (): void {
    $service = app(BusinessService::class);
    $service->create(['name' => 'Split Location Spa', 'locations' => [
        businessLocationPayload(['city' => 'Pokhara', 'latitude' => 28.2, 'longitude' => 83.9]),
        businessLocationPayload(['city' => 'Kathmandu', 'address' => 'Different Street']),
    ]]);
    expect($service->paginate(20, ['city' => 'Pokhara', 'latitude' => 27.7, 'longitude' => 85.3])->total())->toBe(0)
        ->and($service->paginate(20, ['city' => 'Kathmandu', 'address' => 'Street 12'])->total())->toBe(0);
});

it('derives registration service flags from location availability', function (array $availability, bool $locationService, bool $homeService): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $payload = [
        'name' => 'Lotus Spa', 'phone_number' => '9800000000',
        'location_service' => ! $locationService, 'home_service' => ! $homeService,
        'locations' => array_map(fn (string $value) => businessLocationPayload(['service_availability' => $value]), $availability),
    ];
    foreach (Business::KYC_DOCUMENT_TYPES as $type) {
        $payload[$type] = UploadedFile::fake()->create($type.'.pdf', 20, 'application/pdf');
    }

    $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/business/register', $payload)
        ->assertCreated()->assertJsonPath('data.location_service', $locationService)->assertJsonPath('data.home_service', $homeService);
    $this->assertDatabaseHas('businesses', [
        'id' => $response->json('data.id'), 'location_service' => $locationService, 'home_service' => $homeService,
    ]);
})->with([
    'on site only' => [['on_site'], true, false],
    'home only' => [['home'], false, true],
    'both services' => [['home', 'on_site'], true, true],
    'repeated home locations' => [['home', 'home'], false, true],
]);

it('recalculates service flags when locations are replaced', function (): void {
    [$owner, $business] = businessLocationOwner();
    app(BusinessService::class)->update($business, ['locations' => [
        businessLocationPayload(), businessLocationPayload(['service_availability' => 'home']),
    ]]);

    $this->actingAs($owner, 'sanctum')->patchJson('/api/v1/business/'.$business->id, [
        'locations' => [businessLocationPayload(['service_availability' => 'home'])],
    ])->assertOk()->assertJsonPath('data.location_service', false)->assertJsonPath('data.home_service', true);
    $this->assertDatabaseHas('businesses', ['id' => $business->id, 'location_service' => false, 'home_service' => true]);
});
