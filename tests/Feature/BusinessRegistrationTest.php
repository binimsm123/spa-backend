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
        'locations' => [[
            'address' => 'Street 12',
            'city' => 'Kathmandu',
            'latitude' => 27.7,
            'longitude' => 85.3,
            'service_availability' => ($overrides['home_service'] ?? false) && ! ($overrides['location_service'] ?? true) ? 'home' : 'on_site',
        ]],
        'vat_pan_document' => UploadedFile::fake()->create('pan.pdf', 100, 'application/pdf'),
        'business_registration_document' => UploadedFile::fake()->image('registration.jpg'),
        'ward_registration_document' => UploadedFile::fake()->image('ward.png'),
        'municipality_registration_document' => UploadedFile::fake()->image('municipality.png'),
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
        ->assertJsonCount(4, 'data.kyc.documents')
        ->assertJsonMissingPath('data.kyc_documents');

    $business = Business::query()->findOrFail($response->json('data.id'));
    $response->assertJsonPath('data.kyc.documents.0.type', 'vat_pan_document')
        ->assertJsonPath('data.kyc.documents.0.view_url', url('/api/v1/business/'.$business->getKey().'/documents/vat_pan_document'));
    $this->get($response->json('data.kyc.documents.0.view_url'))->assertOk()->assertHeader('Content-Disposition', 'inline; filename='.'vat_pan_document.pdf');
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
    $this->getJson('/api/v1/business/'.$business->getKey())->assertOk()->assertJsonCount(4, 'data.kyc.documents');
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
            'name', 'phone_number', 'locations',
            'vat_pan_document', 'business_registration_document',
            'ward_registration_document', 'municipality_registration_document',
        ]);
    $this->assertDatabaseCount('businesses', 0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('logs in with the same account credentials and returns the owner role after business registration', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create(['password' => 'Customer#1', 'mobile_verified_at' => now()]);
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
        'ward_registration_document' => UploadedFile::fake()->create('ward.pdf', 5121, 'application/pdf'),
        'municipality_registration_document' => UploadedFile::fake()->create('municipality.txt', 10, 'text/plain'),
        'locations' => [[
            'address' => 'Street 12', 'city' => 'Kathmandu', 'latitude' => 91, 'longitude' => -181, 'service_availability' => 'on_site',
        ]],
        'timezone' => 'Invalid/Timezone',
    ]), ['Accept' => 'application/json'])->assertUnprocessable()
        ->assertJsonValidationErrors(['ward_registration_document', 'municipality_registration_document', 'locations.0.latitude', 'locations.0.longitude', 'timezone']);
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

    $this->actingAs($owner, 'sanctum')->get($documentUrl)->assertOk()->assertHeader('Content-Disposition', 'inline; filename='.'vat_pan_document.pdf');
    $this->getJson('/api/v1/business/'.$business->getKey().'/documents/unknown')->assertNotFound();

    $outsider = User::factory()->create();
    $this->actingAs($outsider, 'sanctum')->getJson($documentUrl)->assertForbidden();
    $this->getJson('/api/v1/admin/businesses/'.$business->getKey().'/documents/vat_pan_document')->assertForbidden();

    BusinessUser::query()->create(['business_id' => $business->getKey(), 'user_id' => $outsider->getKey(), 'role' => 'staff', 'is_active' => true]);
    $this->getJson($documentUrl)->assertForbidden();
    $this->getJson('/api/v1/business/'.$business->getKey())->assertOk()->assertJsonMissingPath('data.kyc');

    $admin = actingAsSuperadmin();
    $this->actingAs($admin, 'sanctum')->get('/api/v1/admin/businesses/'.$business->getKey().'/documents/vat_pan_document')
        ->assertOk()->assertHeader('Content-Disposition', 'inline; filename='.'vat_pan_document.pdf');
    $this->getJson('/api/v1/admin/businesses/'.$business->getKey())->assertOk()->assertJsonMissingPath('data.kyc.vat_pan_number')
        ->assertJsonMissingPath('data.kyc.registration_number')
        ->assertJsonPath('data.kyc.documents.0.view_url', url('/api/v1/admin/businesses/'.$business->getKey().'/documents/vat_pan_document'));

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
        'personal_kyc_document' => personalKycDocumentPayload(),
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
        $queries = collect(DB::getQueryLog())->pluck('query')
            ->map(fn (string $sql): string => str_replace(['`', '"'], '', $sql));
    } finally {
        DB::disableQueryLog();
    }

    expect($queries->filter(fn (string $sql): bool => str_starts_with($sql, 'delete from business_locations')))->toHaveCount(0);
    expect($queries->filter(fn (string $sql): bool => str_starts_with($sql, 'update businesses')))->toHaveCount(0);
    expect($queries->filter(fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'from business_locations')))->toHaveCount(1);
    $this->assertDatabaseHas('business_users', ['business_id' => $response->json('data.id'), 'user_id' => $owner->id, 'role' => 'owner']);
});

function personalKycDocumentPayload(): array
{
    return [
        'pan' => UploadedFile::fake()->create('personal-pan.pdf', 100, 'application/pdf'),
        'citizenship_front' => UploadedFile::fake()->image('citizenship-front.jpg'),
        'citizenship_back' => UploadedFile::fake()->image('citizenship-back.png'),
        'nid' => UploadedFile::fake()->image('personal-nid.png'),
    ];
}

it('stores personal KYC documents privately on the registering user', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $response = $this->actingAs($owner, 'sanctum')->post('/api/v1/business/register', businessRegistrationPayload([
        'personal_kyc_document' => personalKycDocumentPayload(),
    ]), ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonMissingPath('data.personal_kyc_document')
        ->assertJsonMissingPath('data.kyc_document');

    $documents = $owner->refresh()->kyc_document;
    expect($documents)->toHaveCount(4)->toHaveKeys(['pan', 'citizenship_front', 'citizenship_back', 'nid']);
    foreach ($documents as $path) {
        expect($path)->toStartWith('user-kyc/'.$owner->getKey().'/');
    }
    Storage::disk('local')->assertExists(array_values($documents));
    Storage::disk('public')->assertMissing(array_values($documents));
    $this->assertDatabaseHas('users', [
        'id' => $owner->id,
        'kyc_document->pan' => $documents['pan'],
        'kyc_document->citizenship_front' => $documents['citizenship_front'],
        'kyc_document->citizenship_back' => $documents['citizenship_back'],
        'kyc_document->nid' => $documents['nid'],
    ]);
    expect($otherUser->refresh()->kyc_document)->toBeNull();
    expect($owner->toArray())->not->toHaveKey('kyc_document');
    $business = Business::query()->findOrFail($response->json('data.id'));
    expect($business->kyc_documents)->not->toHaveKeys(['pan', 'citizenship_front', 'citizenship_back', 'nid']);
});

it('preserves existing personal KYC when the optional group is omitted or null', function (array $extraFields): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $documents = ['pan' => 'user-kyc/existing/pan.pdf'];
    $owner->forceFill(['kyc_document' => $documents])->save();
    Storage::disk('local')->put($documents['pan'], 'existing document');

    $this->actingAs($owner, 'sanctum')->post('/api/v1/business/register', businessRegistrationPayload($extraFields), ['Accept' => 'application/json'])
        ->assertCreated();

    expect($owner->refresh()->kyc_document)->toBe($documents);
    Storage::disk('local')->assertExists($documents['pan']);
})->with([
    'omitted' => [[]],
    'null' => [['personal_kyc_document' => null]],
]);

it('rejects incomplete or invalid personal KYC before saving uploads', function (string $invalidCase, string $errorField): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $documents = personalKycDocumentPayload();

    if ($invalidCase === 'missing') {
        unset($documents['citizenship_back']);
    } elseif ($invalidCase === 'type') {
        $documents['pan'] = UploadedFile::fake()->create('pan.txt', 10, 'text/plain');
    } elseif ($invalidCase === 'size') {
        $documents['nid'] = UploadedFile::fake()->create('nid.pdf', 5121, 'application/pdf');
    } elseif ($invalidCase === 'unknown') {
        $documents['unexpected'] = UploadedFile::fake()->image('extra.png');
    } elseif ($invalidCase === 'empty') {
        $documents = [];
    } else {
        $documents = 'untrusted/client/path.pdf';
    }

    $this->actingAs($owner, 'sanctum')->post('/api/v1/business/register', businessRegistrationPayload([
        'personal_kyc_document' => $documents,
    ]), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors([$errorField]);

    $this->assertDatabaseCount('businesses', 0);
    expect($owner->refresh()->kyc_document)->toBeNull();
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'missing citizenship back' => ['missing', 'personal_kyc_document.citizenship_back'],
    'unsupported file type' => ['type', 'personal_kyc_document.pan'],
    'file above 5 MB' => ['size', 'personal_kyc_document.nid'],
    'unknown document key' => ['unknown', 'personal_kyc_document'],
    'client path instead of uploads' => ['shape', 'personal_kyc_document'],
    'empty document group' => ['empty', 'personal_kyc_document.pan'],
]);

it('cleans up new uploads and preserves existing personal KYC if personal storage fails', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $owner = User::factory()->create();
    $existing = ['pan' => 'user-kyc/existing/pan.pdf'];
    $owner->forceFill(['kyc_document' => $existing])->save();
    Storage::disk('local')->put($existing['pan'], 'existing document');
    $documents = personalKycDocumentPayload();
    $failedFile = Mockery::mock(UploadedFile::class);
    $failedFile->shouldReceive('store')->once()->with('user-kyc/'.$owner->id, 'local')->andReturn(false);
    $documents['citizenship_front'] = $failedFile;

    expect(fn () => app(BusinessRegistrationService::class)->register($owner, businessRegistrationPayload([
        'hero_image' => UploadedFile::fake()->image('spa.jpg'),
        'personal_kyc_document' => $documents,
    ])))->toThrow(RuntimeException::class, 'Unable to store personal verification document.');

    $this->assertDatabaseCount('businesses', 0);
    expect($owner->refresh()->kyc_document)->toBe($existing);
    expect(Storage::disk('local')->allFiles())->toBe([$existing['pan']]);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('saves the supplied date of birth on the registering user', function (?string $existingDob): void {
    Storage::fake('local');
    $owner = User::factory()->create(['dob' => $existingDob]);
    $otherUser = User::factory()->create(['dob' => '1990-01-01']);

    $this->actingAs($owner, 'sanctum')->post('/api/v1/business/register', businessRegistrationPayload([
        'dob' => '1995-04-15',
        'personal_kyc_document' => personalKycDocumentPayload(),
    ]), ['Accept' => 'application/json'])->assertCreated();

    $this->assertDatabaseHas('users', ['id' => $owner->id, 'dob' => '1995-04-15']);
    $this->assertDatabaseHas('users', ['id' => $otherUser->id, 'dob' => '1990-01-01']);
    $documents = $owner->refresh()->kyc_document;
    expect($documents)->toHaveCount(4);
    Storage::disk('local')->assertExists(array_values($documents));
})->with([
    'previously unset' => [null],
    'updates existing DOB' => ['1994-03-10'],
]);

it('preserves the users date of birth when registration DOB is not filled', function (array $extraFields): void {
    Storage::fake('local');
    $owner = User::factory()->create(['dob' => '1995-04-15']);

    $response = $this->actingAs($owner, 'sanctum')->post('/api/v1/business/register', businessRegistrationPayload($extraFields), ['Accept' => 'application/json'])
        ->assertCreated();

    $this->assertDatabaseHas('users', ['id' => $owner->id, 'dob' => '1995-04-15']);
    $business = Business::query()->findOrFail($response->json('data.id'));
    Storage::disk('local')->assertExists(array_values($business->kyc_documents));
})->with([
    'omitted' => [[]],
    'null' => [['dob' => null]],
    'empty multipart text field' => [['dob' => '']],
]);

it('rejects invalid registration DOB without changing the user or storing files', function (string $dob): void {
    Storage::fake('local');
    $owner = User::factory()->create(['dob' => '1995-04-15']);

    $this->actingAs($owner, 'sanctum')->post('/api/v1/business/register', businessRegistrationPayload([
        'dob' => $dob,
    ]), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['dob']);

    $this->assertDatabaseHas('users', ['id' => $owner->id, 'dob' => '1995-04-15']);
    $this->assertDatabaseCount('businesses', 0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'invalid date' => ['not-a-date'],
    'invalid calendar day' => ['1995-02-30'],
    'incorrect format' => ['15/04/1995'],
]);

it('ignores legacy top level addresses when registering locations', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();

    $response = $this->actingAs($owner, 'sanctum')->post('/api/v1/business/register', businessRegistrationPayload([
        'address' => 'Ignored address', 'city' => 'Ignored city', 'latitude' => 91, 'longitude' => -181,
    ]), ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(1, 'data.locations')
        ->assertJsonPath('data.address', 'Street 12')->assertJsonPath('data.city', 'Kathmandu');

    $this->assertDatabaseHas('business_locations', [
        'business_id' => $response->json('data.id'), 'address' => 'Street 12', 'city' => 'Kathmandu', 'latitude' => 27.7, 'longitude' => 85.3,
    ]);
    $this->assertDatabaseMissing('business_locations', ['city' => 'Ignored city']);
    $business = Business::query()->findOrFail($response->json('data.id'));
    Storage::disk('local')->assertExists(array_values($business->kyc_documents));
});

it('rejects registration with only a legacy address and no locations', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $payload = businessRegistrationPayload(['address' => 'Street 12', 'city' => 'Kathmandu', 'latitude' => 27.7, 'longitude' => 85.3]);
    unset($payload['locations']);

    $this->actingAs($owner, 'sanctum')->post('/api/v1/business/register', $payload, ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors(['locations']);

    $this->assertDatabaseCount('businesses', 0);
    $this->assertDatabaseCount('business_locations', 0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('stores personal name and outlet number and restores them for the registering owner', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create(['display_name' => 'Previous Name']);
    $otherUser = User::factory()->create(['display_name' => 'Other Customer']);
    $credentials = $owner->only(['mobile', 'email', 'password']);

    $response = $this->actingAs($owner, 'sanctum')->post('/api/v1/business/register', businessRegistrationPayload([
        'full_name' => '  Anita Shrestha  ',
        'dob' => '1990-02-01',
        'outlet_number' => 3,
        'user_id' => $otherUser->getKey(),
    ]), ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('data.outlet_number', 3)
        ->assertJsonPath('data.personal_details.full_name', 'Anita Shrestha')
        ->assertJsonPath('data.personal_details.dob', '1990-02-01');

    $this->assertDatabaseHas('businesses', ['id' => $response->json('data.id'), 'outlet_number' => 3]);
    expect($owner->refresh()->display_name)->toBe('Anita Shrestha');
    expect($owner->only(['mobile', 'email', 'password']))->toBe($credentials);
    expect($otherUser->refresh()->display_name)->toBe('Other Customer');
    $this->getJson('/api/v1/business/onboarding')->assertOk()
        ->assertJsonPath('data.business.outlet_number', 3)
        ->assertJsonPath('data.personal_details.full_name', 'Anita Shrestha')
        ->assertJsonPath('data.personal_details.dob', '1990-02-01');
    $this->getJson('/api/v1/business/'.$response->json('data.id'))->assertOk()->assertJsonPath('data.outlet_number', 3);
});

it('preserves the account name and outlet default for older registration clients', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create(['display_name' => 'Existing Customer']);

    $this->actingAs($owner, 'sanctum')->post('/api/v1/business/register', businessRegistrationPayload(), ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.outlet_number', 0);

    expect($owner->refresh()->display_name)->toBe('Existing Customer');
});

it('rejects invalid personal names and outlet numbers before storing registration data', function (array $fields, string $invalidField): void {
    Storage::fake('local');
    $owner = User::factory()->create(['display_name' => 'Existing Customer']);

    $this->actingAs($owner, 'sanctum')->post('/api/v1/business/register', businessRegistrationPayload($fields), ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors([$invalidField]);

    expect($owner->refresh()->display_name)->toBe('Existing Customer');
    $this->assertDatabaseCount('businesses', 0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'empty name' => [['full_name' => ' '], 'full_name'],
    'null name' => [['full_name' => null], 'full_name'],
    'oversized name' => [['full_name' => str_repeat('a', 101)], 'full_name'],
    'zero outlets' => [['outlet_number' => 0], 'outlet_number'],
    'negative outlets' => [['outlet_number' => -1], 'outlet_number'],
    'fractional outlets' => [['outlet_number' => 1.5], 'outlet_number'],
    'range instead of exact outlets' => [['outlet_number' => '6+'], 'outlet_number'],
    'outlets above database limit' => [['outlet_number' => 2147483648], 'outlet_number'],
]);
