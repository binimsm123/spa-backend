<?php

use App\Models\Business;
use App\Models\BusinessOnboarding;
use App\Models\User;
use App\Services\BusinessOnboardingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function onboardingPayload(array $changes = []): array
{
    $data = [
        'full_name' => 'Anita Shrestha', 'date_of_birth' => '1990-01-01', 'mobile_number' => '+9779812345678',
        'pan_number' => '123456789', 'business_name' => 'Mobile Spa', 'outlet_count' => '6+',
        'employee_range' => '26_plus', 'service_area' => 'Kathmandu', 'home_service_location' => null,
    ];
    foreach (BusinessOnboarding::DOCUMENTS as $kind) {
        $data[$kind] = UploadedFile::fake()->image($kind.'.png');
    }

    return array_replace($data, $changes);
}

function submitOnboarding(User $user, array $data = [], string $key = 'create-1')
{
    return test()->actingAs($user, 'sanctum')->post('/api/v1/business/onboarding', $data ?: onboardingPayload(), ['Accept' => 'application/json', 'Idempotency-Key' => $key]);
}

it('creates the mobile form as a pending owner application without changing personal KYC or fabricating locations', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $owner->assignRole('customer');
    $before = $owner->only(['mobile', 'password', 'display_name', 'dob', 'kyc_document']);
    $response = submitOnboarding($owner)->assertCreated()
        ->assertJsonPath('data.application.status', 'submitted')
        ->assertJsonPath('data.application.fields.outlet_count', '6+')
        ->assertJsonPath('data.application.fields.employee_range', '26_plus')
        ->assertJsonPath('data.business.is_verified', false)->assertJsonPath('data.business.is_online', false)
        ->assertJsonCount(7, 'data.application.documents')->assertJsonMissingPath('data.application.documents.0.path');
    expect($owner->refresh()->only(array_keys($before)))->toBe($before);
    expect($owner->hasRole('owner'))->toBeTrue();
    $this->assertDatabaseCount('business_locations', 0);
    $this->assertDatabaseHas('business_users', ['business_id' => $response->json('data.business.id'), 'user_id' => $owner->id, 'role' => 'owner']);
    expect(Storage::disk('local')->allFiles())->toHaveCount(7);
    $this->getJson('/api/v1/business/onboarding')->assertOk()->assertJsonPath('data.business.id', $response->json('data.business.id'));
});

it('replays the same file content without creating duplicate records and rejects changed payloads or new creation keys', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $data = onboardingPayload();
    $first = submitOnboarding($owner, $data)->assertCreated();
    submitOnboarding($owner, $data)->assertCreated()->assertExactJson($first->json());
    submitOnboarding($owner, array_replace($data, ['pan_number' => 'changed']))->assertConflict();
    submitOnboarding($owner, $data, 'other-key')->assertConflict();
    $this->assertDatabaseCount('businesses', 1);
    $this->assertDatabaseCount('business_onboardings', 1);
    expect(Storage::disk('local')->allFiles())->toHaveCount(7);
});

it('validates each required document before storing anything', function (string $missing): void {
    Storage::fake('local');
    $data = onboardingPayload();
    unset($data[$missing]);
    submitOnboarding(User::factory()->create(), $data)->assertUnprocessable()->assertJsonValidationErrors($missing);
    $this->assertDatabaseCount('businesses', 0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(BusinessOnboarding::DOCUMENTS);

it('rejects missing invalid fields and unsupported or oversized documents', function (array $changes, string $field): void {
    Storage::fake('local');
    $changes = array_map(fn ($value) => $value instanceof Closure ? $value() : $value, $changes);
    submitOnboarding(User::factory()->create(), onboardingPayload($changes))->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    [['full_name' => ''], 'full_name'], [['date_of_birth' => '31/02/2000'], 'date_of_birth'],
    [['mobile_number' => 'abc'], 'mobile_number'], [['outlet_count' => '8'], 'outlet_count'],
    [['employee_range' => '26'], 'employee_range'], [['service_area' => 'unsupported'], 'service_area'],
    [['personal_pan' => fn () => UploadedFile::fake()->create('bad.txt', 10, 'text/plain')], 'personal_pan'],
    [['citizenship' => fn () => UploadedFile::fake()->image('large.jpg')->size(5121)], 'citizenship'],
]);

it('requires an idempotency key and authentication', function (): void {
    $this->postJson('/api/v1/business/onboarding', [])->assertUnauthorized();
    $this->actingAs(User::factory()->create(), 'sanctum')->postJson('/api/v1/business/onboarding', [])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
});

it('cleans up uploads and rolls back owner assignment on a storage failure', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $file = Mockery::mock(UploadedFile::class);
    $file->shouldReceive('getRealPath')->andReturn(__FILE__);
    $file->shouldReceive('getClientOriginalName')->andReturn('failed.png');
    $file->shouldReceive('getSize')->andReturn(100);
    $file->shouldReceive('store')->once()->andReturn(false);
    $data = onboardingPayload(['citizenship' => $file, 'idempotency_key' => 'failing']);
    expect(fn () => app(BusinessOnboardingService::class)->submit($owner, $data, false))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('businesses', 0);
    $this->assertDatabaseCount('business_onboardings', 0);
    expect($owner->fresh()->hasRole('owner'))->toBeFalse();
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('reviews rejects corrects and approves while retaining unchanged files and denying bypasses', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $created = submitOnboarding($owner)->assertCreated();
    $id = $created->json('data.business.id');
    $app = BusinessOnboarding::firstOrFail();
    $oldFiles = $app->documents;
    $fields = $app->form_data;
    $this->post('/api/v1/business/onboarding/resubmit', $fields, ['Accept' => 'application/json', 'Idempotency-Key' => 'early'])->assertConflict();
    $this->patchJson('/api/v1/business/'.$id, ['name' => 'bypass'])->assertConflict();
    $admin = actingAsSuperadmin();
    $detail = $this->getJson('/api/v1/admin/businesses/'.$id)->assertOk()->assertJsonCount(7, 'data.onboarding.documents');
    $this->get($detail->json('data.onboarding.documents.0.view_url'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->patchJson('/api/v1/admin/businesses/'.$id.'/state', ['action' => 'reject', 'reason' => 'PAN is blurry'])->assertOk();
    $this->actingAs($owner, 'sanctum')->getJson('/api/v1/business/onboarding')->assertJsonPath('data.application.rejection_reason', 'PAN is blurry');
    $this->post('/api/v1/business/onboarding/resubmit', [...$fields, 'pan_number' => 'corrected', 'personal_pan' => UploadedFile::fake()->image('new-pan.png')], ['Accept' => 'application/json', 'Idempotency-Key' => 'correct-1'])
        ->assertAccepted()->assertJsonPath('data.application.revision', 2)->assertJsonPath('data.application.status', 'submitted')->assertJsonPath('data.application.rejection_reason', null);
    $new = $app->fresh()->documents;
    expect($new['personal_pan']['path'])->not->toBe($oldFiles['personal_pan']['path']);
    expect($new['citizenship'])->toBe($oldFiles['citizenship']);
    $this->actingAs($admin, 'sanctum')->patchJson('/api/v1/admin/businesses/'.$id.'/state', ['action' => 'verify'])->assertOk();
    $this->actingAs($owner, 'sanctum')->getJson('/api/v1/business/onboarding')->assertJsonPath('data.application.status', 'verified');
    $this->post('/api/v1/business/onboarding/resubmit', $fields, ['Accept' => 'application/json', 'Idempotency-Key' => 'locked'])->assertConflict();
});

it('returns no application for outsiders and denies unauthorized reviews and document access', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $id = submitOnboarding($owner)->assertCreated()->json('data.business.id');
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/business/onboarding')->assertJsonPath('data.business', null)->assertJsonPath('data.application', null);
    $this->getJson('/api/v1/admin/businesses/'.$id.'/onboarding/documents/personal_pan')->assertForbidden();
    $this->patchJson('/api/v1/admin/businesses/'.$id.'/state', ['action' => 'verify'])->assertForbidden();
});

it('selects the earliest active owner business for legacy multiple-business accounts', function (): void {
    $owner = User::factory()->create();
    $early = Business::create(['name' => 'Earlier', 'slug' => 'earlier', 'created_at' => now()->subDay()]);
    $late = Business::create(['name' => 'Later', 'slug' => 'later']);
    foreach ([$late, $early] as $business) {
        $business->members()->attach($owner->id, ['role' => 'owner', 'is_active' => true]);
    }
    $this->actingAs($owner, 'sanctum')->getJson('/api/v1/business/onboarding')->assertJsonPath('data.business.id', $early->id)->assertJsonPath('data.application', null);
});

it('returns an empty onboarding snapshot for an authenticated account without business membership or roles', function (): void {
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson('/api/v1/business/onboarding')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data', ['business' => null, 'application' => null, 'user_roles' => []]);
});

it('returns an empty onboarding snapshot when there is no eligible owner business', function (string $case): void {
    $owner = User::factory()->create();
    $business = Business::create(['name' => 'Unavailable', 'slug' => 'unavailable']);
    $business->members()->attach($owner->id, [
        'role' => $case === 'staff' ? 'staff' : 'owner',
        'is_active' => $case !== 'inactive',
    ]);
    if ($case === 'deleted') {
        $business->delete();
    }

    $this->actingAs($owner, 'sanctum')->getJson('/api/v1/business/onboarding')
        ->assertOk()
        ->assertJsonPath('data.business', null)
        ->assertJsonPath('data.application', null);
})->with(['staff', 'inactive', 'deleted']);

it('returns a legacy owner business without an application or phone number', function (bool $verified): void {
    $owner = User::factory()->create();
    $business = Business::create(['name' => 'Legacy', 'slug' => 'legacy', 'is_verified' => $verified]);
    $business->members()->attach($owner->id, ['role' => 'owner', 'is_active' => true]);

    $this->actingAs($owner, 'sanctum')->getJson('/api/v1/business/onboarding')
        ->assertOk()
        ->assertJsonPath('data.business.id', $business->id)
        ->assertJsonPath('data.business.phone_number', null)
        ->assertJsonPath('data.business.kyc_status', $verified ? 'verified' : 'submitted')
        ->assertJsonPath('data.application', null);
})->with([false, true]);

it('returns an application with empty document and form data', function (?array $documents): void {
    $owner = User::factory()->create();
    $business = Business::create(['name' => 'Empty application', 'slug' => 'empty-application']);
    $business->members()->attach($owner->id, ['role' => 'owner', 'is_active' => true]);
    $application = BusinessOnboarding::create([
        'business_id' => $business->id, 'owner_user_id' => $owner->id,
        'form_data' => [], 'documents' => $documents ?? [], 'submitted_at' => now(),
    ]);
    if ($documents === null) {
        // The JSON literal null can exist even in a non-nullable JSON column.
        DB::table('business_onboardings')->where('id', $application->id)->update(['documents' => 'null']);
    }

    $this->actingAs($owner, 'sanctum')->getJson('/api/v1/business/onboarding')
        ->assertOk()
        ->assertJsonPath('data.application.fields', [])
        ->assertJsonPath('data.application.documents', [])
        ->assertJsonPath('data.application.reviewed_at', null)
        ->assertJsonPath('data.application.rejection_reason', null);
})->with([
    'empty documents' => [[]],
    'empty document entries' => [['personal_pan' => null, 'citizenship' => []]],
    'null JSON documents' => [null],
    'incomplete document metadata' => [['personal_pan' => ['name' => 'pan.png'], 'citizenship' => 'legacy-path.png']],
]);

it('keeps complete document metadata when other entries are empty', function (): void {
    $owner = User::factory()->create();
    $business = Business::create(['name' => 'Partial documents', 'slug' => 'partial-documents']);
    $business->members()->attach($owner->id, ['role' => 'owner', 'is_active' => true]);
    BusinessOnboarding::create([
        'business_id' => $business->id, 'owner_user_id' => $owner->id, 'form_data' => [], 'submitted_at' => now(),
        'documents' => [
            'personal_pan' => null,
            'citizenship' => ['path' => 'private/citizenship.png', 'name' => 'citizenship.png', 'size' => 100, 'mime_type' => 'image/png'],
        ],
    ]);

    $this->actingAs($owner, 'sanctum')->getJson('/api/v1/business/onboarding')
        ->assertOk()
        ->assertJsonPath('data.application.documents', [[
            'kind' => 'citizenship', 'name' => 'citizenship.png', 'size' => 100, 'mime_type' => 'image/png', 'received' => true,
        ]]);
});

it('requires authentication when reading onboarding', function (): void {
    $this->getJson('/api/v1/business/onboarding')->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});
