<?php

use App\Models\BusinessStaff;
use App\Models\BusinessUser;
use App\Models\Category;
use App\Models\Service;
use App\Models\ServicePhoto;
use App\Models\ServicePrice;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function managementOwner(): array
{
    $owner = User::create(['mobile' => '+97798'.random_int(10000000, 99999999), 'display_name' => 'Roster owner', 'password' => 'Password#1', 'is_active' => true]);
    $owner->assignRole('owner');
    [$business] = createBusinessWithService();
    BusinessUser::create(['business_id' => $business->id, 'user_id' => $owner->id, 'role' => 'owner', 'is_active' => true]);
    test()->actingAs($owner, 'sanctum');

    return [$owner, $business];
}

function managementServicePayload(): array
{
    $category = Category::create(['name' => 'Therapy', 'slug' => 'therapy-'.Str::random(5), 'is_active' => true]);

    return [
        'management_version' => 1, 'name' => 'Deep tissue', 'description' => 'A relaxing treatment.', 'category_id' => $category->id,
        'prices' => [['duration' => '00:45', 'price_minor' => 125000, 'currency' => 'NPR'], ['duration' => '01:30', 'price_minor' => 200000, 'currency' => 'NPR']],
        'images' => [UploadedFile::fake()->image('service.png')],
    ];
}

function managementStaffPayload(): array
{
    return ['name' => 'Anita', 'job_title' => 'Therapist', 'mobile_number' => '9841234567', 'avatar' => UploadedFile::fake()->image('avatar.png')];
}

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
});

it('creates multiple prices and photos, replays retries, and rejects changed request contents', function (): void {
    [, $business] = managementOwner();
    $payload = managementServicePayload();
    $url = "/api/v1/business/{$business->id}/services";
    $first = $this->withHeader('Idempotency-Key', 'service-create')->postJson($url, $payload)->assertCreated();
    $id = $first->json('data.id');
    $first->assertJsonPath('data.service_prices.0.duration', '00:45')->assertJsonCount(1, 'data.photos');
    $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.id', $id);
    $this->postJson($url, [...$payload, 'name' => 'Changed'])->assertConflict();
    $this->postJson($url, [...$payload, 'images' => [UploadedFile::fake()->image('changed.png', 15, 15)]])->assertConflict();
    expect(Service::where('business_id', $business->id)->count())->toBe(2)
        ->and(ServicePrice::where('service_id', $id)->count())->toBe(2)
        ->and(Storage::disk('public')->allFiles())->toHaveCount(1);
});

it('adds edits lists and deletes services with many images and H:i price durations', function (): void {
    [, $business] = managementOwner();
    $payload = managementServicePayload();
    $payload['prices'] = array_reverse($payload['prices']);
    $payload['images'] = array_map(fn ($i) => UploadedFile::fake()->image("photo$i.jpg"), range(1, 4));
    $url = "/api/v1/business/{$business->id}/services";

    $created = $this->withHeader('Idempotency-Key', 'many-create')->post($url, $payload, ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonCount(4, 'data.photos')
        ->assertJsonPath('data.service_prices.0.duration', '00:45')
        ->assertJsonPath('data.service_prices.1.duration', '01:30');
    $id = $created->json('data.id');
    $photos = ServicePhoto::where('service_id', $id)->orderBy('position')->get();
    $this->assertDatabaseHas('service_prices', ['service_id' => $id, 'duration' => '00:45:00']);
    $this->assertDatabaseHas('service_prices', ['service_id' => $id, 'duration' => '01:30:00']);
    $this->getJson($url.'?search=Deep%20tissue')->assertOk()->assertJsonCount(4, 'data.items.0.photos');
    $this->getJson($url.'?search=Deep%20tissue&page=1')->assertOk()->assertJsonCount(4, 'data.items.0.photos');
    $this->getJson("$url/$id")->assertOk()->assertJsonCount(4, 'data.photos');

    $retainedIds = $photos->slice(1)->pluck('id')->all();
    $updated = $this->withHeader('Idempotency-Key', 'many-edit')->post("$url/$id", [
        '_method' => 'PATCH', 'management_version' => 1,
        'retained_photo_ids' => $retainedIds,
        'images' => [UploadedFile::fake()->image('extra1.jpg'), UploadedFile::fake()->image('extra2.jpg')],
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(5, 'data.photos');
    $updated->assertJsonPath('data.photos.0.id', $retainedIds[0]);
    Storage::disk('public')->assertMissing($photos->first()->path);
    foreach ($photos->slice(1) as $photo) {
        Storage::disk('public')->assertExists($photo->path);
    }
    expect(Storage::disk('public')->allFiles())->toHaveCount(5);

    $this->withHeader('Idempotency-Key', 'many-empty')->patchJson("$url/$id", [
        'management_version' => 1, 'retained_photo_ids' => [],
    ])->assertUnprocessable()->assertJsonValidationErrors('images');
    expect(ServicePhoto::where('service_id', $id)->count())->toBe(5);

    $this->withHeader('Idempotency-Key', 'many-delete')->deleteJson("$url/$id")->assertOk();
    $this->assertSoftDeleted('services', ['id' => $id]);
    $this->assertDatabaseMissing('service_photos', ['service_id' => $id]);
    expect(Storage::disk('public')->allFiles())->toBe([])
        ->and(ServicePrice::where('service_id', $id)->count())->toBe(2);
    $this->getJson("$url/$id")->assertNotFound();
    $this->getJson($url.'?search=Deep%20tissue')->assertOk()->assertJsonCount(0, 'data.items');
});

it('validates every required modern service field', function (string $field): void {
    [, $business] = managementOwner();
    $payload = managementServicePayload();
    unset($payload[$field]);
    $this->withHeader('Idempotency-Key', 'required')->postJson("/api/v1/business/{$business->id}/services", $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['name', 'description', 'category_id', 'prices', 'images']);

it('rejects invalid service prices and files', function (string $field, mixed $value): void {
    [, $business] = managementOwner();
    $payload = managementServicePayload();
    data_set($payload, $field, is_callable($value) ? $value() : $value);
    $this->withHeader('Idempotency-Key', 'invalid')->postJson("/api/v1/business/{$business->id}/services", $payload)->assertUnprocessable();
    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'zero duration' => ['prices.0.duration', '00:00'],
    'duplicate duration' => ['prices.1.duration', '00:45'],
    'numeric duration' => ['prices.0.duration', 45],
    'seconds in duration' => ['prices.0.duration', '00:45:00'],
    'invalid minutes' => ['prices.0.duration', '01:60'],
    'invalid hours' => ['prices.0.duration', '24:00'],
    'fractional price' => ['prices.0.price_minor', 1.5],
    'zero price' => ['prices.0.price_minor', 0],
    'wrong currency' => ['prices.0.currency', 'USD'],
    'mixed scalar prices' => ['price_minor', 100],
    'oversize image' => ['images', fn () => [UploadedFile::fake()->image('large.png')->size(5121)]],
    'unsupported image' => ['images', fn () => [UploadedFile::fake()->create('image.svg', 2, 'image/svg+xml')]],
    'empty image' => ['images', fn () => [UploadedFile::fake()->create('empty.png', 0, 'image/png')]],
    'no images' => ['images', []],
]);

it('keeps unchanged price IDs and photos and preserves replaced price history', function (): void {
    [, $business] = managementOwner();
    $payload = managementServicePayload();
    $url = "/api/v1/business/{$business->id}/services";
    $first = $this->withHeader('Idempotency-Key', 'create')->postJson($url, $payload)->assertCreated();
    $id = $first->json('data.id');
    $photo = $first->json('data.photos.0.id');
    $oldPrice = $first->json('data.service_prices.0.id');
    $unchangedPrice = $first->json('data.service_prices.1.id');
    $payload['prices'][0]['price_minor'] = 135000;
    unset($payload['images']);
    $payload['retained_photo_ids'] = [$photo];
    $this->withHeader('Idempotency-Key', 'update')->patchJson("$url/$id", $payload)->assertOk()
        ->assertJsonPath('data.photos.0.id', $photo)->assertJsonPath('data.service_prices.1.id', $unchangedPrice);
    expect(ServicePrice::find($oldPrice)->is_current)->toBeFalse()
        ->and(ServicePrice::where('service_id', $id)->count())->toBe(3)
        ->and(Storage::disk('public')->allFiles())->toHaveCount(1);
    $this->withHeader('Idempotency-Key', 'replace')->postJson("$url/$id", [
        '_method' => 'PATCH', 'management_version' => 1, 'retained_photo_ids' => '[]',
        'images' => [UploadedFile::fake()->image('new.png')],
    ])->assertOk()->assertJsonCount(1, 'data.photos');
    expect(ServicePhoto::find($photo))->toBeNull()->and(Storage::disk('public')->allFiles())->toHaveCount(1);
});

it('preserves legacy JSON service requests and makes reads and deletion business scoped', function (): void {
    [, $business] = managementOwner();
    $url = "/api/v1/business/{$business->id}/services";
    $id = $this->postJson($url, ['name' => 'Legacy', 'price_minor' => 300000, 'duration' => '01:00'])->assertCreated()->json('data.id');
    $this->patchJson("$url/$id", ['price_minor' => 350000])->assertOk()->assertJsonPath('data.service_prices.0.price_minor', 350000);
    $this->getJson($url)->assertOk()->assertJsonCount(2, 'data.items')->assertJsonMissingPath('data.pagination');
    $this->getJson($url.'?page=1&per_page=1&search=LEGACY')->assertOk()->assertJsonPath('data.pagination.total', 1);
    $this->getJson("$url/$id")->assertOk();
    [$other] = createBusinessWithService();
    $this->deleteJson("/api/v1/business/{$other->id}/services/$id")->assertForbidden();
    $this->deleteJson("$url/$id")->assertOk();
    $this->assertSoftDeleted('services', ['id' => $id]);
    $this->getJson("$url/$id")->assertNotFound();
    expect(ServicePrice::where('service_id', $id)->count())->toBe(2);
});

it('rolls back service records, price history, and uploads when a later image fails', function (): void {
    [, $business] = managementOwner();
    $payload = managementServicePayload();
    $payload['images'][] = UploadedFile::fake()->image('second.png');
    $disk = Storage::disk('public');
    $mock = Mockery::mock($disk)->makePartial();
    $writes = 0;
    $mock->shouldReceive('putFileAs')->twice()->andReturnUsing(function ($path, $file, $name) use ($disk, &$writes) {
        if (++$writes === 2) {
            throw new RuntimeException('Storage failed');
        }

        return $disk->putFileAs($path, $file, $name);
    });
    $mock->shouldReceive('delete')->andReturnUsing(fn ($path) => $disk->delete($path));
    Storage::shouldReceive('disk')->with('public')->andReturn($mock);
    $this->withHeader('Idempotency-Key', 'failure')->postJson("/api/v1/business/{$business->id}/services", $payload)->assertServerError();
    expect(Service::where('business_id', $business->id)->count())->toBe(1)
        ->and($disk->allFiles())->toBe([]);
});

it('requires keys and rejects inactive categories and foreign retained photos', function (): void {
    [, $business] = managementOwner();
    $payload = managementServicePayload();
    $url = "/api/v1/business/{$business->id}/services";
    $this->postJson($url, $payload)->assertUnprocessable();
    Category::find($payload['category_id'])->update(['is_active' => false]);
    $this->withHeader('Idempotency-Key', 'inactive')->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('category_id');
    $this->getJson("/api/v1/business/{$business->id}/service-categories")->assertOk()->assertJsonCount(0, 'data.items');
    $service = $business->services()->first();
    $this->withHeader('Idempotency-Key', 'foreign')->patchJson("$url/{$service->id}", ['management_version' => 1, 'retained_photo_ids' => [(string) Str::ulid()]])->assertUnprocessable()->assertJsonValidationErrors('retained_photo_ids');
});

it('manages a private roster without creating users, memberships, roles, or notifications', function (): void {
    [, $business] = managementOwner();
    $counts = collect(['users', 'business_users', 'model_has_roles', 'notifications'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);
    $url = "/api/v1/business/{$business->id}/staff-roster";
    $payload = managementStaffPayload();
    $first = $this->withHeader('Idempotency-Key', 'staff')->postJson($url, $payload)->assertCreated()->assertJsonPath('data.mobile_number', '+9779841234567')->assertJsonMissingPath('data.avatar_path');
    $id = $first->json('data.id');
    $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.id', $id);
    $this->postJson($url, [...$payload, 'name' => 'Changed'])->assertConflict();
    foreach ($counts as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
    $this->getJson("$url/$id")->assertOk();
    $this->getJson("$url/$id/avatar")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->withHeader('Idempotency-Key', 'staff-edit')->postJson("$url/$id", ['_method' => 'PATCH', 'job_title' => 'Senior therapist', 'remove_avatar' => 1])->assertOk()->assertJsonPath('data.avatar_url', null);
    expect(Storage::disk('local')->allFiles())->toBe([]);
    $this->getJson($url.'?search=Senior')->assertOk()->assertJsonPath('data.pagination.total', 1);
    $this->deleteJson("$url/$id")->assertOk();
    $this->assertSoftDeleted('business_staff', ['id' => $id]);
    $this->getJson("$url/$id")->assertNotFound();
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'data.items');
    $this->withHeader('Idempotency-Key', 're-add')->postJson($url, managementStaffPayload())->assertCreated();
});

it('validates roster fields and duplicate active contacts', function (): void {
    [, $business] = managementOwner();
    $url = "/api/v1/business/{$business->id}/staff-roster";
    $this->withHeader('Idempotency-Key', 'first')->postJson($url, managementStaffPayload())->assertCreated();
    $this->withHeader('Idempotency-Key', 'duplicate')->postJson($url, managementStaffPayload())->assertUnprocessable()->assertJsonValidationErrors('mobile_number');
    foreach (['name', 'job_title', 'mobile_number'] as $field) {
        $payload = managementStaffPayload();
        unset($payload[$field]);
        $this->withHeader('Idempotency-Key', 'required-'.$field)->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    $this->withHeader('Idempotency-Key', 'bad-phone')->postJson($url, [...managementStaffPayload(), 'mobile_number' => 'abc'])->assertUnprocessable();
    $this->withHeader('Idempotency-Key', 'large-avatar')->postJson($url, [...managementStaffPayload(), 'mobile_number' => '+9779841234568', 'avatar' => UploadedFile::fake()->image('large.png')->size(5121)])->assertUnprocessable();
});

it('enforces fresh KYC state and membership permissions on both resource types', function (string $state, int $expected): void {
    [$owner, $business] = managementOwner();
    if ($state === 'unverified') {
        $business->update(['is_verified' => false, 'status' => 'pending']);
    } elseif ($state === 'suspended') {
        $business->update(['status' => 'suspended']);
    } elseif ($state === 'staff') {
        BusinessUser::where('business_id', $business->id)->update(['role' => 'staff']);
    } elseif ($state === 'inactive') {
        BusinessUser::where('business_id', $business->id)->update(['is_active' => false]);
    }
    $this->withHeader('Idempotency-Key', 'service')->postJson("/api/v1/business/{$business->id}/services", managementServicePayload())->assertStatus($expected);
    $this->withHeader('Idempotency-Key', 'roster')->postJson("/api/v1/business/{$business->id}/staff-roster", managementStaffPayload())->assertStatus($expected);
    $this->getJson("/api/v1/business/{$business->id}/services")->assertStatus($state === 'inactive' ? 403 : 200);
})->with(['pending' => ['unverified', 409], 'suspended' => ['suspended', 409], 'staff' => ['staff', 403], 'inactive' => ['inactive', 403]]);

it('blocks foreign records and private portraits even for owners of another business', function (): void {
    [$owner, $business] = managementOwner();
    $id = $this->withHeader('Idempotency-Key', 'roster')->postJson("/api/v1/business/{$business->id}/staff-roster", managementStaffPayload())->assertCreated()->json('data.id');
    [, $other] = managementOwner();
    $this->getJson("/api/v1/business/{$other->id}/staff-roster/$id")->assertNotFound();
    $this->getJson("/api/v1/business/{$other->id}/staff-roster/$id/avatar")->assertNotFound();
    $this->getJson("/api/v1/business/{$business->id}/staff-roster/$id/avatar")->assertForbidden();
});

it('cleans up a roster portrait when saving the record fails', function (): void {
    [, $business] = managementOwner();
    BusinessStaff::creating(function (): void {
        throw new RuntimeException('Simulated database write failure');
    });
    $this->withHeader('Idempotency-Key', 'staff-failure')->postJson("/api/v1/business/{$business->id}/staff-roster", managementStaffPayload())->assertServerError();
    expect(BusinessStaff::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('permits managers to manage a roster but preserves existing staff accounts', function (): void {
    [$owner, $business] = managementOwner();
    BusinessUser::where('business_id', $business->id)->update(['role' => 'manager']);
    $this->withHeader('Idempotency-Key', 'manager')->postJson("/api/v1/business/{$business->id}/staff-roster", managementStaffPayload())->assertCreated();
    $this->getJson("/api/v1/business/{$business->id}/staff")->assertOk()->assertJsonPath('data.items.0.id', $owner->id);
});

it('rejects anonymous access to service and roster endpoints', function (): void {
    [$business] = createBusinessWithService();
    $this->getJson("/api/v1/business/{$business->id}/services")->assertUnauthorized();
    $this->getJson("/api/v1/business/{$business->id}/staff-roster")->assertUnauthorized();
});
