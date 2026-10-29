<?php

use App\Models\BusinessUser;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

function staffUpdateFixture(string $actorRole = 'owner'): array
{
    $actor = User::factory()->create();
    [$business] = createBusinessWithService();
    BusinessUser::query()->create([
        'business_id' => $business->getKey(), 'user_id' => $actor->getKey(),
        'role' => $actorRole, 'is_active' => true,
    ]);
    $staff = User::factory()->create([
        'display_name' => 'Original Name', 'job_title' => 'Therapist', 'image' => 'avatars/original.jpg', 'is_active' => true,
    ]);
    $membership = BusinessUser::query()->create([
        'business_id' => $business->getKey(), 'user_id' => $staff->getKey(),
        'role' => 'staff', 'is_active' => false,
    ]);

    return [$actor, $business, $staff, $membership];
}

it('lets owners and managers edit staff details and replace the image', function (string $actorRole): void {
    Storage::fake('public');
    [$actor, $business, $staff] = staffUpdateFixture($actorRole);
    Storage::disk('public')->put($staff->image, 'original image');
    [$otherBusiness] = createBusinessWithService();
    $otherMembership = BusinessUser::query()->create([
        'business_id' => $otherBusiness->getKey(), 'user_id' => $staff->getKey(),
        'role' => 'staff', 'is_active' => true,
    ]);
    $password = $staff->password;

    $response = $this->actingAs($actor, 'sanctum')->post('/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey(), [
        '_method' => 'PATCH', 'name' => 'Updated Name', 'role' => 'manager',
        'job_title' => 'Senior Therapist', 'image' => UploadedFile::fake()->image('new.jpg'),
    ], ['Accept' => 'application/json'])->assertOk()
        ->assertJsonPath('message', 'Staff member updated.')
        ->assertJsonPath('data.display_name', 'Updated Name')
        ->assertJsonPath('data.job_title', 'Senior Therapist')
        ->assertJsonPath('data.role', 'manager')
        ->assertJsonPath('data.is_active', false);

    $staff->refresh();
    $this->assertDatabaseHas('users', ['id' => $staff->getKey(), 'display_name' => 'Updated Name', 'job_title' => 'Senior Therapist']);
    $this->assertDatabaseHas('business_users', [
        'business_id' => $business->getKey(), 'user_id' => $staff->getKey(), 'role' => 'manager', 'is_active' => false,
    ]);
    expect($otherMembership->refresh()->role)->toBe('staff')
        ->and($staff->password)->toBe($password);
    Storage::disk('public')->assertExists($staff->image);
    Storage::disk('public')->assertMissing('avatars/original.jpg');
    $response->assertJsonPath('data.image', url('storage/'.$staff->image))->assertJsonMissingPath('data.password');
})->with(['owner', 'manager']);

it('preserves omitted staff fields and ignores fields outside the update contract', function (): void {
    [$actor, $business, $staff, $membership] = staffUpdateFixture();
    $original = $staff->only(['password', 'mobile', 'image', 'job_title', 'is_active']);

    $this->actingAs($actor, 'sanctum')->patchJson('/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey(), [
        'name' => 'Updated Name', 'password' => 'Changed#123', 'mobile_number' => '9841234567', 'is_active' => true,
    ])->assertOk()->assertJsonPath('data.display_name', 'Updated Name');

    expect($staff->refresh()->only(array_keys($original)))->toBe($original)
        ->and($membership->refresh()->role)->toBe('staff')
        ->and($membership->is_active)->toBeFalse();
});

it('allows explicitly clearing the job title and image', function (): void {
    Storage::fake('public');
    [$actor, $business, $staff] = staffUpdateFixture();
    Storage::disk('public')->put($staff->image, 'original image');

    $this->actingAs($actor, 'sanctum')->patchJson('/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey(), [
        'job_title' => null, 'image' => null,
    ])->assertOk()->assertJsonPath('data.job_title', null)->assertJsonPath('data.image', null);

    $this->assertDatabaseHas('users', ['id' => $staff->getKey(), 'job_title' => null, 'image' => null]);
    Storage::disk('public')->assertMissing('avatars/original.jpg');
});

it('returns 404 when the target user is not a member of the business', function (): void {
    [$actor, $business] = staffUpdateFixture();
    $outsider = User::factory()->create(['display_name' => 'Outsider']);

    $this->actingAs($actor, 'sanctum')->patchJson('/api/v1/business/'.$business->getKey().'/staff/'.$outsider->getKey(), [
        'name' => 'Updated Name',
    ])->assertNotFound();

    expect($outsider->refresh()->display_name)->toBe('Outsider');
});

it('returns 403 when a staff member attempts to edit staff details', function (): void {
    [$actor, $business, $staff] = staffUpdateFixture('staff');

    $this->actingAs($actor, 'sanctum')->patchJson('/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey(), [
        'name' => 'Updated Name',
    ])->assertForbidden();

    expect($staff->refresh()->display_name)->toBe('Original Name');
});

it('returns 403 when the editor belongs to another business', function (): void {
    [, $business, $staff] = staffUpdateFixture();
    $outsider = User::factory()->create();

    $this->actingAs($outsider, 'sanctum')->patchJson('/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey(), [
        'name' => 'Updated Name',
    ])->assertForbidden();

    expect($staff->refresh()->display_name)->toBe('Original Name');
});

it('returns 401 when updating staff without authentication', function (): void {
    [, $business, $staff] = staffUpdateFixture();

    $this->patchJson('/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey(), ['name' => 'Updated Name'])
        ->assertUnauthorized();
});

it('rejects invalid staff updates without changing the user', function (string $kind, string $field): void {
    Storage::fake('public');
    [$actor, $business, $staff] = staffUpdateFixture();
    $invalidValue = match ($kind) {
        'blank name' => '',
        'long name' => str_repeat('a', 121),
        'invalid role' => 'superadmin',
        'null role' => null,
        'long title' => str_repeat('a', 81),
        'non-image' => UploadedFile::fake()->create('file.pdf', 10, 'application/pdf'),
        'large image' => UploadedFile::fake()->image('portrait.jpg')->size(5121),
    };

    $this->actingAs($actor, 'sanctum')->post('/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey(), [
        '_method' => 'PATCH', $field => $invalidValue,
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseHas('users', ['id' => $staff->getKey(), 'display_name' => 'Original Name', 'job_title' => 'Therapist']);
    Storage::disk('public')->assertDirectoryEmpty('avatars');
})->with([
    ['blank name', 'name'], ['long name', 'name'], ['invalid role', 'role'], ['null role', 'role'],
    ['long title', 'job_title'], ['non-image', 'image'], ['large image', 'image'],
]);

it('rolls back staff details and removes the new image when saving the role fails', function (): void {
    Storage::fake('public');
    [$actor, $business, $staff, $membership] = staffUpdateFixture();
    Storage::disk('public')->put($staff->image, 'original image');
    $event = 'eloquent.updating: '.BusinessUser::class;
    Event::listen($event, fn () => throw new RuntimeException('Unable to update membership.'));

    try {
        $this->actingAs($actor, 'sanctum')->post('/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey(), [
            '_method' => 'PATCH', 'name' => 'Updated Name', 'role' => 'manager',
            'image' => UploadedFile::fake()->image('new.jpg'),
        ], ['Accept' => 'application/json'])->assertServerError();
    } finally {
        Event::forget($event);
    }

    expect($staff->refresh()->display_name)->toBe('Original Name')
        ->and($staff->image)->toBe('avatars/original.jpg')
        ->and($membership->refresh()->role)->toBe('staff');
    Storage::disk('public')->assertExists('avatars/original.jpg');
    expect(Storage::disk('public')->allFiles('avatars'))->toBe(['avatars/original.jpg']);
});
