<?php

use App\Models\Business;
use App\Models\Category;
use App\Models\User;

it('matches the collection contract for the home catalog', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/home?latitude=27.7087&longitude=85.3222&timezone=Asia/Kathmandu')
        ->assertOk()
        ->assertJsonPath('message', 'Home catalog loaded.')
        ->assertJsonStructure(['data' => [
            'user' => ['id', 'display_name', 'mobile_number', 'avatar_url'],
            'unread_notification_count', 'location', 'banner',
            'categories',
        ]]);
});

it('lists distinct nonempty business cities in alphabetical order', function (): void {
    $user = User::factory()->create();

    foreach (['Pokhara', 'Kathmandu', 'Pokhara', null, ''] as $index => $city) {
        Business::query()->create([
            'name' => 'City Test Spa '.$index,
            'slug' => 'city-test-spa-'.$index,
            'city' => $city,
        ]);
    }

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/locations')
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'Cities loaded.',
            'data' => ['Kathmandu', 'Pokhara'],
        ]);
});

it('returns an empty city list when no businesses exist', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/locations')
        ->assertOk()->assertJsonPath('data', []);
});

it('requires authentication to list business cities', function (): void {
    $this->getJson('/api/v1/locations')->assertUnauthorized();
});

it('matches the collection contract for offers', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/offers?status=active&page=1&per_page=20')
        ->assertOk()
        ->assertJsonPath('message', 'Offers loaded.')
        ->assertJsonStructure(['data' => [
            'items', 'pagination' => ['page', 'per_page', 'total', 'total_pages'],
        ]]);
});

it('filters the catalog by the city and address stored on each business', function (): void {
    $user = User::factory()->create();
    [$business] = createBusinessWithService();
    [$other] = createBusinessWithService();
    $business->update(['address' => 'Street 12', 'city' => 'Lalitpur']);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/businesses?city=Lalitpur&address=Street')
        ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $business->getKey())
        ->assertJsonPath('data.items.0.address', 'Street 12');

    $this->getJson('/api/v1/businesses?city=Kathmandu')->assertOk()->assertJsonCount(0, 'data.items');
    $this->getJson('/api/v1/businesses?city=Lalitpur&address=Missing')->assertOk()->assertJsonCount(0, 'data.items');

    $this->getJson('/api/v1/businesses/'.$business->getKey())->assertOk()
        ->assertJsonCount(7, 'data.hours')->assertJsonPath('data.city', 'Lalitpur');
});

it('lists current duration and pricing under service prices in the home catalog', function (): void {
    $user = User::factory()->create();
    [$business, $service] = createBusinessWithService();
    $category = Category::query()->create(['name' => 'Massage', 'slug' => 'massage', 'is_active' => true]);
    $service->update(['category_id' => $category->getKey()]);
    $service->prices()->create(['price_minor' => 400000, 'currency' => 'NPR', 'duration' => '00:45', 'is_current' => false]);
    $service->prices()->create(['price_minor' => 650000, 'currency' => 'NPR', 'duration' => '01:30', 'is_current' => true]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/home')->assertOk()
        ->assertJsonCount(1, 'data.categories.0.services')
        ->assertJsonCount(2, 'data.categories.0.services.0.service_prices');
    $prices = collect($response->json('data.categories.0.services.0.service_prices'));
    expect($prices->pluck('duration')->all())->toContain('01:00:00', '01:30:00')->not->toContain('00:45:00');
    expect($prices->pluck('price_minor')->all())->toContain(500000, 650000);
    expect($response->json('data.categories.0.services.0'))->not->toHaveKey('duration_minutes');
});
