<?php

use App\Models\BusinessUser;
use App\Models\Category;
use App\Models\ServicePhoto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function serviceManagementFixture(): array
{
    Storage::fake('public');
    $owner = User::factory()->create();
    [$business] = createBusinessWithService();
    BusinessUser::query()->create([
        'business_id' => $business->getKey(), 'user_id' => $owner->getKey(), 'role' => 'owner', 'is_active' => true,
    ]);
    $category = Category::query()->create(['name' => 'Therapy', 'slug' => 'therapy-'.Str::random(5), 'is_active' => true]);
    test()->actingAs($owner, 'sanctum');

    return [$business, [
        'management_version' => 1, 'name' => 'Deep tissue', 'description' => 'A relaxing treatment.',
        'category_id' => $category->getKey(),
        'prices' => [
            ['duration' => '00:45', 'price_minor' => 125000, 'currency' => 'NPR'],
            ['duration' => '01:30', 'price_minor' => 200000, 'currency' => 'NPR'],
        ],
        'images' => [UploadedFile::fake()->image('service.png')],
    ]];
}

function captureServiceManagementQueries(Closure $action): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $action();

        return DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
}

it('does not reload newly created prices and photos to serialize a service', function (): void {
    [$business, $payload] = serviceManagementFixture();
    $payload['images'] = array_map(fn ($i) => UploadedFile::fake()->image("photo$i.png"), range(1, 4));

    $queries = captureServiceManagementQueries(function () use ($business, $payload): void {
        $this->withHeader('Idempotency-Key', 'query-create')->postJson('/api/v1/business/'.$business->getKey().'/services', $payload)
            ->assertCreated()->assertJsonCount(2, 'data.service_prices')->assertJsonCount(4, 'data.photos')
            ->assertJsonPath('data.category.id', $payload['category_id']);
    });
    $relationshipReads = array_filter($queries, fn ($query) => str_starts_with($query['query'], 'select')
        && (str_contains($query['query'], '`service_prices`') || str_contains($query['query'], '`service_photos`')));
    expect($relationshipReads)->toBeEmpty();
});

it('does not add a query per retained photo when editing a service', function (): void {
    [$business, $payload] = serviceManagementFixture();
    $url = '/api/v1/business/'.$business->getKey().'/services';
    $queryCounts = [];

    foreach ([2, 8] as $photoCount) {
        $payload['images'] = array_map(fn ($i) => UploadedFile::fake()->image("photo$i.png"), range(1, $photoCount));
        $created = $this->withHeader('Idempotency-Key', 'create-'.$photoCount)->postJson($url, $payload)->assertCreated();
        $id = $created->json('data.id');
        $retainedIds = array_reverse(array_column($created->json('data.photos'), 'id'));
        $queries = captureServiceManagementQueries(function () use ($url, $id, $payload, $retainedIds, $photoCount): void {
            $this->withHeader('Idempotency-Key', 'edit-'.$photoCount)->patchJson($url.'/'.$id, [
                'management_version' => 1, 'prices' => $payload['prices'], 'retained_photo_ids' => $retainedIds,
            ])->assertOk()->assertJsonCount($photoCount, 'data.photos')
                ->assertJsonPath('data.photos.0.id', $retainedIds[0]);
        });
        $queryCounts[] = count($queries);
        expect(ServicePhoto::query()->where('service_id', $id)->orderBy('position')->pluck('id')->all())->toBe($retainedIds);
    }
    expect($queryCounts[1])->toBe($queryCounts[0]);
});

it('keeps service list queries constant as the catalog grows', function (): void {
    [$business, $payload] = serviceManagementFixture();
    $url = '/api/v1/business/'.$business->getKey().'/services';
    $this->withHeader('Idempotency-Key', 'list-create')->postJson($url, $payload)->assertCreated();
    $smallQueries = captureServiceManagementQueries(fn () => $this->getJson($url)->assertOk()->assertJsonCount(2, 'data.items'));

    foreach (range(1, 10) as $index) {
        $service = $business->services()->create([
            'name' => 'Extra '.$index, 'slug' => 'extra-'.$index, 'category_id' => $payload['category_id'],
        ]);
        $service->prices()->create(['duration' => '01:00', 'price_minor' => 100000, 'currency' => 'NPR', 'is_current' => true]);
        $service->photos()->create(['path' => 'service.png', 'position' => 0]);
    }

    $largeQueries = captureServiceManagementQueries(fn () => $this->getJson($url)->assertOk()->assertJsonCount(12, 'data.items'));
    expect(count($largeQueries))->toBe(count($smallQueries));
});

it('permits managers to create a service', function (): void {
    [$business, $payload] = serviceManagementFixture();
    BusinessUser::query()->where('business_id', $business->getKey())->update(['role' => 'manager']);

    $this->withHeader('Idempotency-Key', 'manager-create')->postJson('/api/v1/business/'.$business->getKey().'/services', $payload)
        ->assertCreated()->assertJsonPath('data.name', 'Deep tissue');
});

it('returns persisted service settings when creating a service', function (array $settings, array $expected): void {
    [$business, $payload] = serviceManagementFixture();

    $created = $this->withHeader('Idempotency-Key', 'default-create')
        ->postJson('/api/v1/business/'.$business->getKey().'/services', array_replace($payload, $settings))
        ->assertCreated()
        ->assertJsonPath('data.max_people', $expected['max_people'])
        ->assertJsonPath('data.is_bookable', $expected['is_bookable'])
        ->assertJsonPath('data.status', $expected['status']);

    $this->assertDatabaseHas('services', ['id' => $created->json('data.id'), ...$expected]);
})->with([
    'defaults' => [[], ['max_people' => 1, 'is_bookable' => true, 'status' => 'active']],
    'explicit settings' => [
        ['max_people' => 4, 'is_bookable' => false, 'status' => 'inactive'],
        ['max_people' => 4, 'is_bookable' => false, 'status' => 'inactive'],
    ],
]);

it('rejects anonymous service creation with 401', function (): void {
    [$business] = createBusinessWithService();

    $this->postJson('/api/v1/business/'.$business->getKey().'/services', [
        'name' => 'Unauthorized service', 'duration' => '01:00', 'price_minor' => 100000,
    ])->assertUnauthorized();

    $this->assertDatabaseMissing('services', ['name' => 'Unauthorized service']);
});

it('rejects service creation for another business with 403 without saving uploads', function (): void {
    [$business, $payload] = serviceManagementFixture();
    $this->actingAs(User::factory()->create(), 'sanctum');

    $this->withHeader('Idempotency-Key', 'foreign-business')
        ->postJson('/api/v1/business/'.$business->getKey().'/services', $payload)
        ->assertForbidden()->assertJsonPath('message', 'You do not manage this business.');

    $this->assertDatabaseMissing('services', ['business_id' => $business->getKey(), 'name' => $payload['name']]);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('creates service photos in upload order regardless of array keys', function (array $keys): void {
    [$business, $payload] = serviceManagementFixture();
    $payload['images'] = array_combine($keys, [
        UploadedFile::fake()->image('first.png'), UploadedFile::fake()->image('second.png'),
    ]);

    $created = $this->withHeader('Idempotency-Key', 'keyed-images')
        ->post('/api/v1/business/'.$business->getKey().'/services', $payload, ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonCount(2, 'data.photos');

    $photos = ServicePhoto::query()->where('service_id', $created->json('data.id'))->orderBy('position')->get();
    expect($photos->pluck('position')->all())->toBe([0, 1]);
    expect($photos->pluck('id')->all())->toBe(array_column($created->json('data.photos'), 'id'));
    foreach ($photos as $photo) {
        Storage::disk('public')->assertExists($photo->path);
    }
})->with([
    'named keys' => [['cover', 'detail']],
    'sparse keys' => [[5, 2]],
]);

it('makes a deleted service inactive and unbookable', function (): void {
    [$business] = serviceManagementFixture();
    $service = $business->services()->firstOrFail();
    $url = '/api/v1/business/'.$business->getKey().'/services/'.$service->getKey();

    $this->deleteJson($url)->assertOk();

    $this->assertSoftDeleted($service);
    $this->assertDatabaseHas('services', [
        'id' => $service->getKey(), 'status' => 'inactive', 'is_bookable' => false,
    ]);
    $this->getJson($url)->assertNotFound();
});

it('preserves service write restrictions without saving uploads', function (string $kind, int $status, string $message): void {
    [$business, $payload] = serviceManagementFixture();
    if ($kind === 'staff') {
        BusinessUser::query()->where('business_id', $business->getKey())->update(['role' => 'staff']);
    } elseif ($kind === 'inactive membership') {
        BusinessUser::query()->where('business_id', $business->getKey())->update(['is_active' => false]);
    } else {
        $business->update(['status' => 'inactive']);
    }

    $this->withHeader('Idempotency-Key', 'restricted-create')->postJson('/api/v1/business/'.$business->getKey().'/services', $payload)
        ->assertStatus($status)->assertJsonPath('message', $message);

    $this->assertDatabaseMissing('services', ['business_id' => $business->getKey(), 'name' => 'Deep tissue']);
    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    ['staff', 403, 'Your role cannot manage services or staff.'],
    ['inactive membership', 403, 'You do not manage this business.'],
    ['inactive business', 409, 'An active business with verified KYC is required to make changes.'],
]);
