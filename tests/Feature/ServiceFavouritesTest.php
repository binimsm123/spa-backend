<?php

use App\Models\User;

it('paginates a customer’s saved services newest first and keeps saves idempotent', function (): void {
    [$business, $first] = createBusinessWithService();
    [, $second] = createBusinessWithService();
    $first->update(['description' => 'A saved treatment', 'image_path' => null]);
    $second->update(['image_path' => 'services/sample.png']);
    $this->actingAs(User::factory()->create(), 'sanctum');

    $this->getJson('/api/v1/favourites')->assertOk()->assertJsonPath('data.items', []);
    $this->putJson("/api/v1/favourites/{$first->id}")->assertOk()
        ->assertJsonPath('data', ['service_id' => $first->id, 'is_favourite' => true]);
    $this->putJson("/api/v1/favourites/{$second->id}")->assertOk();
    $this->putJson("/api/v1/favourites/{$first->id}")->assertOk();

    $this->getJson('/api/v1/favourites?page=1&per_page=1')->assertOk()
        ->assertJsonPath('data.items.0.service_id', $second->id)
        ->assertJsonPath('data.items.0.image_url', url('storage/services/sample.png'))
        ->assertJsonPath('data.pagination.total', 2)
        ->assertJsonPath('data.pagination.has_more', true);
    $this->getJson('/api/v1/favourites?page=2&per_page=1')->assertOk()
        ->assertJsonPath('data.items.0', [
            'service_id' => $first->id,
            'business_id' => $business->id,
            'name' => $first->name,
            'description' => 'A saved treatment',
            'image_url' => null,
        ])
        ->assertJsonPath('data.pagination.page', 2);
});

it('scopes favourites and business detail heart state to the signed-in account', function (): void {
    [$business, $service] = createBusinessWithService();
    $url = "/api/v1/favourites/{$service->id}";
    $details = "/api/v1/businesses/{$business->id}";
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $this->getJson('/api/v1/favourites')->assertUnauthorized();
    $this->putJson($url)->assertUnauthorized();
    $this->deleteJson($url)->assertUnauthorized();

    $this->actingAs($owner, 'sanctum')->putJson($url)->assertOk();
    $this->getJson($details)->assertOk()->assertJsonPath('data.services.0.is_favourite', true);

    $this->actingAs($other, 'sanctum')->getJson('/api/v1/favourites')->assertOk()->assertJsonPath('data.items', []);
    $this->deleteJson($url)->assertOk()->assertJsonPath('data.is_favourite', false);
    $this->getJson($details)->assertOk()->assertJsonPath('data.services.0.is_favourite', false);

    $this->actingAs($owner, 'sanctum')->getJson($details)->assertOk()->assertJsonPath('data.services.0.is_favourite', true);
    $this->deleteJson($url)->assertOk()
        ->assertJsonPath('data', ['service_id' => $service->id, 'is_favourite' => false]);
    $this->deleteJson($url)->assertOk()->assertJsonPath('data.is_favourite', false);
    $this->getJson('/api/v1/favourites')->assertOk()->assertJsonPath('data.items', []);
    $this->getJson($details)->assertOk()->assertJsonPath('data.services.0.is_favourite', false);
});
