<?php

use App\Models\Category;
use App\Models\Offer;
use App\Models\User;

it('returns saved business contact profile and media fields in customer responses', function (string $route, string $prefix): void {
    $this->freezeTime();
    [$business, $service] = createBusinessWithService();
    $category = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true]);
    $service->update(['category_id' => $category->id]);
    $owner = bookingApprover($business);
    $gallery = ['https://media.example.com/spa/reception.jpg', 'https://media.example.com/spa/treatment-room.jpg'];
    $videos = ['https://media.example.com/services/massage.mp4'];
    $images = ['https://media.example.com/offers/welcome.jpg'];
    $photo = $service->photos()->create(['path' => 'service-photos/massage.jpg', 'position' => 0]);

    $this->actingAs($owner, 'sanctum')->patchJson('/api/v1/business/'.$business->id, [
        'phone_number' => '+9779800000123',
        'years_of_experience' => 12,
        'is_insured' => true,
        'gallery_image_urls' => $gallery,
    ])->assertOk();
    $this->patchJson('/api/v1/business/'.$business->id.'/services/'.$service->id, [
        'video_urls' => $videos,
    ])->assertOk()->assertJsonPath('data.video_urls', $videos);
    $offer = $this->postJson('/api/v1/business/'.$business->id.'/offers', [
        'code' => 'WELCOME', 'title' => 'Welcome offer', 'discount_type' => 'percentage', 'discount_value' => 10,
        'starts_at' => now()->subDay()->toIso8601String(), 'expires_at' => now()->addDay()->toIso8601String(),
        'image_urls' => $images,
    ])->assertCreated()->assertJsonPath('data.image_urls', $images);

    $this->actingAs(User::factory()->create(), 'sanctum')->getJson(str_replace('{business}', $business->id, $route))
        ->assertOk()
        ->assertJsonPath($prefix.'.phone_number', '+9779800000123')
        ->assertJsonPath($prefix.'.years_of_experience', 12)
        ->assertJsonPath($prefix.'.is_insured', true)
        ->assertJsonPath($prefix.'.gallery_image_urls', $gallery)
        ->assertJsonPath($prefix.'.services.0.video_urls', $videos)
        ->assertJsonPath($prefix.'.services.0.photos.0.id', $photo->id)
        ->assertJsonPath($prefix.'.services.0.photos.0.url', url('storage/service-photos/massage.jpg'))
        ->assertJsonPath($prefix.'.offers.0.image_urls', $images)
        ->assertJsonMissingPath($prefix.'.kyc_documents');
    $this->assertDatabaseHas('businesses', ['id' => $business->id, 'phone_number' => '+9779800000123', 'years_of_experience' => 12, 'is_insured' => true]);
    expect($business->refresh()->gallery_image_urls)->toBe($gallery);
    expect($service->refresh()->video_urls)->toBe($videos);
    expect(Offer::query()->findOrFail($offer->json('data.id'))->image_urls)->toBe($images);
})->with([
    'business detail' => ['/api/v1/businesses/{business}', 'data'],
    'home catalog with services' => ['/api/v1/home?services=1', 'data.categories.0.businesses.0'],
]);

it('counts all business bookings and only completed booking guests', function (string $route, string $prefix): void {
    [$business, $service] = createBusinessWithService();
    $category = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true]);
    $service->update(['category_id' => $category->id]);
    [$otherBusiness] = createBusinessWithService();
    $customer = User::factory()->create();
    foreach ([['completed', 3], ['completed', 2], ['confirmed', 4], ['cancelled', 5], ['awaiting_payment', 6], ['in_progress', 7], ['expired', 8], ['refunded', 9]] as [$status, $people]) {
        $business->bookings()->create([
            'user_id' => $customer->id, 'status' => $status, 'people_count' => $people,
            'appointment_date' => '2026-11-17', 'starts_at' => '2026-11-17 10:00:00', 'ends_at' => '2026-11-17 11:00:00',
        ]);
    }
    $otherBusiness->bookings()->create([
        'user_id' => $customer->id, 'status' => 'completed', 'people_count' => 50,
        'appointment_date' => '2026-11-17', 'starts_at' => '2026-11-17 10:00:00', 'ends_at' => '2026-11-17 11:00:00',
    ]);

    $this->actingAs($customer, 'sanctum')->getJson(str_replace('{business}', $business->id, $route))
        ->assertOk()
        ->assertJsonPath($prefix.'.guests_served', 5)
        ->assertJsonPath($prefix.'.bookings_count', 8)
        ->assertJsonMissingPath($prefix.'.bookings');
})->with([
    'business detail' => ['/api/v1/businesses/{business}', 'data'],
    'home catalog without services' => ['/api/v1/home', 'data.categories.0.businesses.0'],
    'business listing' => ['/api/v1/businesses?business_id={business}', 'data.items.0'],
]);

it('includes business profile media and current offers in home without loading services', function (): void {
    $this->freezeTime();
    [$business, $service] = createBusinessWithService();
    $category = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true]);
    $service->update(['category_id' => $category->id]);
    foreach ([
        ['ACTIVE', 'active', now()->subDay(), now()->addDay()],
        ['PAUSED', 'paused', now()->subDay(), now()->addDay()],
        ['FUTURE', 'active', now()->addDay(), now()->addDays(2)],
        ['EXPIRED', 'active', now()->subDays(2), now()->subDay()],
    ] as [$code, $status, $startsAt, $expiresAt]) {
        $business->offers()->create([
            'code' => $code, 'title' => $code, 'discount_type' => 'percentage', 'discount_value' => 10,
            'status' => $status, 'starts_at' => $startsAt, 'expires_at' => $expiresAt,
        ]);
    }

    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/home')
        ->assertOk()
        ->assertJsonPath('data.categories.0.businesses.0.years_of_experience', null)
        ->assertJsonPath('data.categories.0.businesses.0.is_insured', false)
        ->assertJsonPath('data.categories.0.businesses.0.gallery_image_urls', [])
        ->assertJsonPath('data.categories.0.businesses.0.guests_served', 0)
        ->assertJsonPath('data.categories.0.businesses.0.bookings_count', 0)
        ->assertJsonCount(1, 'data.categories.0.businesses.0.offers')
        ->assertJsonPath('data.categories.0.businesses.0.offers.0.code', 'ACTIVE')
        ->assertJsonPath('data.categories.0.businesses.0.offers.0.image_urls', [])
        ->assertJsonMissingPath('data.categories.0.businesses.0.services');
});

it('returns stable defaults for businesses without profile media or bookings', function (bool $online): void {
    [$business] = createBusinessWithService();
    $business->update(['is_online' => $online]);

    $response = $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/businesses/'.$business->id)
        ->assertOk()
        ->assertJsonPath('data.phone_number', null)
        ->assertJsonPath('data.years_of_experience', null)
        ->assertJsonPath('data.is_insured', false)
        ->assertJsonPath('data.gallery_image_urls', [])
        ->assertJsonPath('data.guests_served', 0)
        ->assertJsonPath('data.bookings_count', 0);

    if ($online) {
        $response->assertJsonPath('data.services.0.video_urls', [])
            ->assertJsonPath('data.services.0.photos', []);
    } else {
        $response->assertJsonMissingPath('data.services');
    }
})->with([true, false]);

it('returns empty image lists for offers without media', function (): void {
    $this->freezeTime();
    [$business] = createBusinessWithService();
    $business->offers()->create([
        'code' => 'NO-IMAGE', 'title' => 'Offer without media', 'discount_type' => 'percentage', 'discount_value' => 10,
        'starts_at' => now()->subDay(), 'expires_at' => now()->addDay(),
    ]);

    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/businesses/'.$business->id)
        ->assertOk()->assertJsonPath('data.offers.0.image_urls', []);
});

it('allows owners to replace and clear public profile media', function (): void {
    [$business, $service] = createBusinessWithService();
    $business->update([
        'years_of_experience' => 10, 'gallery_image_urls' => ['https://media.example.com/old.jpg'], 'is_insured' => true,
    ]);
    $service->update(['video_urls' => ['https://media.example.com/old.mp4']]);
    $offer = $business->offers()->create([
        'code' => 'CLEAR', 'title' => 'Offer', 'discount_type' => 'percentage', 'discount_value' => 10,
        'starts_at' => now()->subDay(), 'expires_at' => now()->addDay(), 'image_urls' => ['https://media.example.com/offer.jpg'],
    ]);
    $this->actingAs(bookingApprover($business), 'sanctum')->patchJson('/api/v1/business/'.$business->id, [
        'phone_number' => null, 'years_of_experience' => null, 'gallery_image_urls' => [], 'is_insured' => false,
    ])->assertOk()->assertJsonPath('data.gallery_image_urls', [])->assertJsonPath('data.is_insured', false);
    $this->patchJson('/api/v1/business/'.$business->id.'/services/'.$service->id, ['video_urls' => []])
        ->assertOk()->assertJsonPath('data.video_urls', []);
    $this->patchJson('/api/v1/business/'.$business->id.'/offers/'.$offer->id, ['image_urls' => []])
        ->assertOk()->assertJsonPath('data.image_urls', []);

    expect($business->refresh()->gallery_image_urls)->toBe([]);
    expect($business->years_of_experience)->toBeNull();
    expect($service->refresh()->video_urls)->toBe([]);
    expect($offer->refresh()->image_urls)->toBe([]);
});

it('returns 422 without saving invalid business profile fields', function (string $field, mixed $value, string $error): void {
    [$business] = createBusinessWithService();

    $this->actingAs(bookingApprover($business), 'sanctum')->patchJson('/api/v1/business/'.$business->id, [$field => $value])
        ->assertUnprocessable()->assertJsonValidationErrors($error);

    expect($business->refresh()->years_of_experience)->toBeNull();
    expect($business->gallery_image_urls)->toBeNull();
    expect($business->is_insured)->toBeFalse();
})->with([
    'negative experience' => ['years_of_experience', -1, 'years_of_experience'],
    'fractional experience' => ['years_of_experience', 1.5, 'years_of_experience'],
    'experience overflow' => ['years_of_experience', 65536, 'years_of_experience'],
    'invalid insurance flag' => ['is_insured', 'insured', 'is_insured'],
    'invalid gallery URL' => ['gallery_image_urls', ['not-a-url'], 'gallery_image_urls.0'],
    'unsafe gallery scheme' => ['gallery_image_urls', ['javascript:alert(1)'], 'gallery_image_urls.0'],
    'gallery object' => ['gallery_image_urls', ['cover' => 'https://media.example.com/cover.jpg'], 'gallery_image_urls'],
]);

it('returns 422 without saving invalid service video URLs', function (mixed $urls, string $error): void {
    [$business, $service] = createBusinessWithService();

    $this->actingAs(bookingApprover($business), 'sanctum')
        ->patchJson('/api/v1/business/'.$business->id.'/services/'.$service->id, ['video_urls' => $urls])
        ->assertUnprocessable()->assertJsonValidationErrors($error);

    expect($service->refresh()->video_urls)->toBeNull();
})->with([
    'invalid URL' => [['not-a-url'], 'video_urls.0'],
    'unsafe scheme' => [['file:///tmp/video.mp4'], 'video_urls.0'],
    'not an array' => ['https://media.example.com/video.mp4', 'video_urls'],
]);

it('returns 422 without saving invalid offer image URLs', function (): void {
    [$business] = createBusinessWithService();

    $this->actingAs(bookingApprover($business), 'sanctum')->postJson('/api/v1/business/'.$business->id.'/offers', [
        'code' => 'INVALID', 'title' => 'Invalid image', 'discount_type' => 'percentage', 'discount_value' => 10,
        'starts_at' => now()->subDay()->toIso8601String(), 'expires_at' => now()->addDay()->toIso8601String(),
        'image_urls' => ['ftp://media.example.com/offer.jpg'],
    ])->assertUnprocessable()->assertJsonValidationErrors('image_urls.0');

    $this->assertDatabaseCount('offers', 0);
});
