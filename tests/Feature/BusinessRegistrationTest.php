<?php

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\User;
use App\Services\BusinessRegistrationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function businessRegistrationPayload(array $overrides = []): array
{
    return [
        'name' => 'Kamal Spa',
        'phone_number' => '+9779800000000',
        'address' => 'Street 12',
        'city' => 'Kathmandu',
        'vat_pan_document' => UploadedFile::fake()->create('pan.pdf', 100, 'application/pdf'),
        'business_registration_document' => UploadedFile::fake()->image('registration.jpg'),
        'ward_registration_document' => UploadedFile::fake()->image('ward.png'),
        'municipality_registration_document' => UploadedFile::fake()->image('municipality.png'),
        'nid_front' => UploadedFile::fake()->image('nid.png'),
        'owner_identity_front' => UploadedFile::fake()->image('front.jpg'),
        'owner_identity_back' => UploadedFile::fake()->image('back.png'),
        ...$overrides,
    ];
}

it('registers a pending business with a scoped owner and private KYC documents', function (array $extraFields): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $owner->assignRole('customer');
    $originalCredentials = $owner->only(['mobile', 'email', 'password']);

    $response = $this->actingAs($owner, 'sanctum')->post('/api/v1/business/register', businessRegistrationPayload([
        ...$extraFields,
        'latitude' => 27.7,
        'longitude' => 85.3,
        'status' => 'active',
        'is_verified' => true,
        'is_online' => true,
    ]), ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.is_verified', false)
        ->assertJsonPath('data.is_online', false)
        ->assertJsonPath('data.home_service', $extraFields['home_service'] ?? false)
        ->assertJsonPath('data.location_service', $extraFields['location_service'] ?? true)
        ->assertJsonPath('data.staff_number', $extraFields['staff_number'] ?? 0)
        ->assertJsonMissingPath('data.is_insured')
        ->assertJsonPath('data.my_role', 'owner')
        ->assertJsonPath('data.user_roles', fn (array $roles): bool => collect($roles)->sort()->values()->all() === ['customer', 'owner'])
        ->assertJsonMissingPath('data.kyc.owner_identity_type')
        ->assertJsonMissingPath('data.kyc.vat_pan_number')
        ->assertJsonMissingPath('data.kyc.registration_number')
        ->assertJsonCount(7, 'data.kyc.documents')
        ->assertJsonMissingPath('data.kyc_documents');

    $business = Business::query()->findOrFail($response->json('data.id'));
    $response->assertJsonPath('data.kyc.documents.0.type', 'vat_pan_document')
        ->assertJsonPath('data.kyc.documents.0.download_url', url('/api/v1/business/'.$business->getKey().'/documents/vat_pan_document'));
    $this->get($response->json('data.kyc.documents.0.download_url'))->assertOk()->assertDownload('vat_pan_document.pdf');
    $this->assertDatabaseHas('business_locations', [
        'business_id' => $business->getKey(), 'address' => 'Street 12', 'city' => 'Kathmandu', 'latitude' => 27.7, 'longitude' => 85.3,
    ]);
    $this->assertDatabaseHas('business_users', ['business_id' => $business->getKey(), 'user_id' => $owner->getKey(), 'role' => 'owner', 'is_active' => true]);
    $this->assertDatabaseCount('users', 1);
    expect($owner->refresh()->only(['mobile', 'email', 'password']))->toBe($originalCredentials);
    expect($owner->hasAllRoles(['customer', 'owner']))->toBeTrue();
    Storage::disk('local')->assertExists(array_values($business->kyc_documents));
    expect($business->toArray())->not->toHaveKeys(['vat_pan_number', 'registration_number', 'owner_identity_type', 'search_name', 'kyc_documents']);

    $this->getJson('/api/v1/business/my')->assertOk()->assertJsonPath('data.items.0.id', $business->getKey())
        ->assertJsonMissingPath('data.items.0.kyc');
    $this->getJson('/api/v1/business/'.$business->getKey())->assertOk()->assertJsonCount(7, 'data.kyc.documents');
})->with([
    'without removed fields' => [[]],
    'with service options and staff count' => [[
        'home_service' => true, 'location_service' => false, 'staff_number' => 6,
    ]],
    'ignores removed fields from older clients' => [[
        'vat_pan_number' => '123456789',
        'registration_number' => 'REG-12345',
        'owner_identity_type' => 'passport',
        'is_insured' => true,
    ]],
]);

it('requires the business information and all KYC documents', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();

    $this->actingAs($owner, 'sanctum')->postJson('/api/v1/business/register', [])
        ->assertUnprocessable()->assertJsonValidationErrors([
            'name', 'phone_number', 'address', 'city',
            'vat_pan_document', 'business_registration_document',
            'ward_registration_document', 'municipality_registration_document', 'nid_front', 'owner_identity_front', 'owner_identity_back',
        ]);
    $this->assertDatabaseCount('businesses', 0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('logs in with the same account credentials and returns the owner role after business registration', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create(['password' => 'Customer#1']);
    $owner->assignRole('customer');

    $this->actingAs($owner, 'sanctum')->post('/api/v1/business/register', businessRegistrationPayload(), ['Accept' => 'application/json'])
        ->assertCreated();

    $this->postJson('/api/v1/auth/login', ['mobile_number' => $owner->mobile, 'password' => 'Customer#1'])
        ->assertOk()->assertJsonPath('data.user.id', $owner->getKey())
        ->assertJsonPath('data.user.roles', fn (array $roles): bool => collect($roles)->sort()->values()->all() === ['customer', 'owner'])
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);
    $this->assertDatabaseCount('users', 1);
});

it('rejects invalid coordinates, timezones, and invalid or oversized uploads', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();

    $this->actingAs($owner, 'sanctum')->post('/api/v1/business/register', businessRegistrationPayload([
        'owner_identity_front' => UploadedFile::fake()->create('front.pdf', 5121, 'application/pdf'),
        'owner_identity_back' => UploadedFile::fake()->create('back.txt', 10, 'text/plain'),
        'latitude' => 91, 'longitude' => -181, 'timezone' => 'Invalid/Timezone',
    ]), ['Accept' => 'application/json'])->assertUnprocessable()
        ->assertJsonValidationErrors(['owner_identity_front', 'owner_identity_back', 'latitude', 'longitude', 'timezone']);
    $this->assertDatabaseCount('businesses', 0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('requires authentication to register a business', function (): void {
    $this->postJson('/api/v1/business/register', [])->assertUnauthorized();
});

it('allows owners and authorized admins to download documents but blocks outsiders and staff', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $business = app(BusinessRegistrationService::class)->register($owner, businessRegistrationPayload());
    $documentUrl = '/api/v1/business/'.$business->getKey().'/documents/vat_pan_document';

    $this->actingAs($owner, 'sanctum')->get($documentUrl)->assertOk()->assertDownload('vat_pan_document.pdf');
    $this->getJson('/api/v1/business/'.$business->getKey().'/documents/unknown')->assertNotFound();

    $outsider = User::factory()->create();
    $this->actingAs($outsider, 'sanctum')->getJson($documentUrl)->assertForbidden();
    $this->getJson('/api/v1/admin/businesses/'.$business->getKey().'/documents/vat_pan_document')->assertForbidden();

    BusinessUser::query()->create(['business_id' => $business->getKey(), 'user_id' => $outsider->getKey(), 'role' => 'staff', 'is_active' => true]);
    $this->getJson($documentUrl)->assertForbidden();
    $this->getJson('/api/v1/business/'.$business->getKey())->assertOk()->assertJsonMissingPath('data.kyc');

    $admin = actingAsSuperadmin();
    $this->actingAs($admin, 'sanctum')->get('/api/v1/admin/businesses/'.$business->getKey().'/documents/vat_pan_document')
        ->assertOk()->assertDownload('vat_pan_document.pdf');
    $this->getJson('/api/v1/admin/businesses/'.$business->getKey())->assertOk()->assertJsonMissingPath('data.kyc.vat_pan_number')
        ->assertJsonMissingPath('data.kyc.registration_number')
        ->assertJsonPath('data.kyc.documents.0.download_url', url('/api/v1/admin/businesses/'.$business->getKey().'/documents/vat_pan_document'));

    $business->update(['status' => 'active', 'is_online' => true]);
    $this->actingAs($outsider, 'sanctum')->getJson('/api/v1/businesses/'.$business->getKey())->assertOk()
        ->assertJsonMissingPath('data.kyc')->assertJsonMissingPath('data.kyc_documents');
});

it('rolls back the business and removes uploaded files if owner assignment fails', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $unsavedOwner = User::factory()->make(['id' => (string) Str::ulid()]);

    expect(fn () => app(BusinessRegistrationService::class)->register($unsavedOwner, businessRegistrationPayload([
        'hero_image' => UploadedFile::fake()->image('spa.jpg'),
    ])))
        ->toThrow(QueryException::class);

    $this->assertDatabaseCount('businesses', 0);
    $this->assertDatabaseCount('business_users', 0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('stores a public business image and returns its URL while keeping KYC documents private', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $owner = User::factory()->create();

    $response = $this->actingAs($owner, 'sanctum')->post('/api/v1/business/register', businessRegistrationPayload([
        'hero_image' => UploadedFile::fake()->image('spa-interior.jpg'),
        'hero_image_path' => 'untrusted/client/path.jpg',
    ]), ['Accept' => 'application/json'])->assertCreated();

    $business = Business::query()->findOrFail($response->json('data.id'));
    expect($business->hero_image_path)->toStartWith('business-images/'.$business->id.'/')
        ->not->toBe('untrusted/client/path.jpg');
    $response->assertJsonPath('data.hero_image_url', url('storage/'.$business->hero_image_path));
    Storage::disk('public')->assertExists($business->hero_image_path);
    Storage::disk('local')->assertMissing($business->hero_image_path);
    Storage::disk('local')->assertExists(array_values($business->kyc_documents));
    Storage::disk('public')->assertMissing(array_values($business->kyc_documents));
    $this->assertDatabaseHas('businesses', ['id' => $business->id, 'hero_image_path' => $business->hero_image_path]);
});

it('rejects invalid or oversized business images before storing files', function (string $filename, int $size, string $mime): void {
    Storage::fake('local');
    Storage::fake('public');
    $owner = User::factory()->create();

    $image = $mime === 'image/jpeg'
        ? UploadedFile::fake()->image($filename)->size($size)
        : UploadedFile::fake()->create($filename, $size, $mime);

    $this->actingAs($owner, 'sanctum')->post('/api/v1/business/register', businessRegistrationPayload([
        'hero_image' => $image,
    ]), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['hero_image']);

    $this->assertDatabaseCount('businesses', 0);
    expect(Storage::disk('public')->allFiles())->toBe([]);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'document instead of an image' => ['spa.pdf', 100, 'application/pdf'],
    'SVG image' => ['spa.svg', 100, 'image/svg+xml'],
    'oversized image' => ['spa.jpg', 5121, 'image/jpeg'],
]);

it('removes the public image when KYC storage fails', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $owner = User::factory()->create();
    $document = Mockery::mock(UploadedFile::class);
    $document->shouldReceive('store')->once()->with(Mockery::type('string'), 'local')->andReturn(false);

    expect(fn () => app(BusinessRegistrationService::class)->register($owner, businessRegistrationPayload([
        'hero_image' => UploadedFile::fake()->image('spa.jpg'),
        'vat_pan_document' => $document,
    ])))->toThrow(RuntimeException::class, 'Unable to store business verification document.');

    $this->assertDatabaseCount('businesses', 0);
    expect(Storage::disk('public')->allFiles())->toBe([]);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('fails registration when the business image cannot be stored', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $owner = User::factory()->create();
    $image = Mockery::mock(UploadedFile::class);
    $image->shouldReceive('store')->once()->with(Mockery::type('string'), 'public')->andReturn(false);

    expect(fn () => app(BusinessRegistrationService::class)->register($owner, businessRegistrationPayload([
        'hero_image' => $image,
    ])))->toThrow(RuntimeException::class, 'Unable to store business image.');

    $this->assertDatabaseCount('businesses', 0);
    expect(Storage::disk('public')->allFiles())->toBe([]);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('registers multiple locations without redundant business and location queries', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $owner->assignRole('customer');
    $payload = businessRegistrationPayload(['locations' => [
        ['address' => 'Street 12', 'city' => 'Kathmandu', 'latitude' => 27.7, 'longitude' => 85.3, 'service_availability' => 'on_site'],
        ['address' => 'Jhamsikhel Road', 'city' => 'Lalitpur', 'latitude' => 27.67, 'longitude' => 85.31, 'service_availability' => 'home'],
    ]]);
    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        $response = $this->actingAs($owner, 'sanctum')->post('/api/v1/business/register', $payload, ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonCount(2, 'data.locations')
            ->assertJsonPath('data.home_service', true)->assertJsonPath('data.location_service', true)
            ->assertJsonPath('data.staff_number', 0)->assertJsonPath('data.timezone', 'Asia/Kathmandu')
            ->assertJsonPath('data.kyc.documents.0.type', 'vat_pan_document');
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
    }

    expect($queries->filter(fn (string $sql): bool => str_starts_with($sql, 'delete from `business_locations`')))->toHaveCount(0);
    expect($queries->filter(fn (string $sql): bool => str_starts_with($sql, 'update `businesses`')))->toHaveCount(0);
    expect($queries->filter(fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'from `business_locations`')))->toHaveCount(1);
    $this->assertDatabaseHas('business_users', ['business_id' => $response->json('data.id'), 'user_id' => $owner->id, 'role' => 'owner']);
});
